<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Administration\Telegram;

use UniversalTelegram\Administration\Telegram\BotSetupWizardState;
use UniversalTelegram\ChatWidget\ChatWidgetAvailability;
use UniversalTelegram\Conversations\ChatProfileResolver;
use UniversalTelegram\Core\Configuration\Settings;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;
use UniversalTelegram\Telegram\Configuration\DestinationKind;
use UniversalTelegram\Telegram\Configuration\DestinationRepository;
use WP_UnitTestCase;

final class BotSetupWizardStateTest extends WP_UnitTestCase {

	private BotProfileRepository $bots;
	private DestinationRepository $destinations;
	private Settings $settings;
	private BotSetupWizardState $state;

	protected function setUp(): void {
		parent::setUp();

		$schema_health      = new SchemaHealth();
		$vault              = new CredentialVault();
		$this->bots         = new BotProfileRepository( $schema_health, $vault );
		$this->destinations = new DestinationRepository( $schema_health );
		$this->settings     = new Settings();

		$chat_profiles     = new ChatProfileResolver( $this->bots, $this->destinations );
		$chat_widget_avail = new ChatWidgetAvailability( $this->settings, $chat_profiles );
		$this->state       = new BotSetupWizardState( $chat_profiles, $chat_widget_avail, $this->destinations );
	}

	public function test_no_bot_configured_is_entirely_incomplete(): void {
		$this->assertNull( $this->state->default_bot() );
		$this->assertFalse( $this->state->step_one_complete() );
		$this->assertFalse( $this->state->step_four_complete() );
		$this->assertFalse( $this->state->step_five_complete() );
		$this->assertSame( 1, $this->state->current_step() );
		$this->assertFalse( $this->state->is_complete() );
	}

	public function test_bot_created_but_token_never_validated_is_step_one_incomplete(): void {
		$this->bots->create( 'My Bot', '123456789:unvalidated-token' );

		$this->assertFalse( $this->state->step_one_complete() );
		$this->assertSame( 1, $this->state->current_step() );
	}

	public function test_bot_validated_with_no_destination_is_step_four_incomplete(): void {
		$bot = $this->bots->create( 'My Bot', '123456789:validated-token' );
		$this->bots->update_telegram_identity( $bot->id(), 111, 'my_bot' );

		$this->assertTrue( $this->state->step_one_complete() );
		$this->assertFalse( $this->state->step_four_complete() );
		$this->assertSame( 4, $this->state->current_step() );
	}

	public function test_destination_wrong_kind_is_step_four_incomplete(): void {
		$bot = $this->bots->create( 'My Bot', '123456789:validated-token' );
		$this->bots->update_telegram_identity( $bot->id(), 111, 'my_bot' );
		$this->destinations->create( $bot->id(), DestinationKind::PRIVATE, '12345', null, 'Website Support' );

		$this->assertFalse( $this->state->step_four_complete() );
	}

	public function test_destination_with_topic_id_is_step_four_incomplete(): void {
		$bot = $this->bots->create( 'My Bot', '123456789:validated-token' );
		$this->bots->update_telegram_identity( $bot->id(), 111, 'my_bot' );
		$this->destinations->create( $bot->id(), DestinationKind::SUPERGROUP, '-1001234567890', 42, 'Website Support' );

		$this->assertFalse( $this->state->step_four_complete() );
	}

	public function test_destination_correct_but_webhook_unregistered_is_step_five_incomplete(): void {
		$bot = $this->bots->create( 'My Bot', '123456789:validated-token' );
		$this->bots->update_telegram_identity( $bot->id(), 111, 'my_bot' );
		$this->destinations->create( $bot->id(), DestinationKind::SUPERGROUP, '-1001234567890', null, 'Website Support' );

		$this->assertTrue( $this->state->step_four_complete() );
		$this->assertFalse( $this->state->step_five_complete() );
		$this->assertSame( 5, $this->state->current_step() );
	}

	public function test_webhook_uncertain_is_step_five_incomplete(): void {
		$bot = $this->bots->create( 'My Bot', '123456789:validated-token' );
		$this->bots->update_telegram_identity( $bot->id(), 111, 'my_bot' );
		$this->destinations->create( $bot->id(), DestinationKind::SUPERGROUP, '-1001234567890', null, 'Website Support' );
		$this->bots->mark_uncertain( $bot->id() );

		$this->assertFalse( $this->state->step_five_complete() );
	}

	public function test_webhook_registered_but_widget_disabled_is_step_five_incomplete(): void {
		$bot = $this->bots->create( 'My Bot', '123456789:validated-token' );
		$this->bots->update_telegram_identity( $bot->id(), 111, 'my_bot' );
		$this->destinations->create( $bot->id(), DestinationKind::SUPERGROUP, '-1001234567890', null, 'Website Support' );
		$this->bots->mark_registered( $bot->id() );

		// chat_widget_enabled left at its default (false).
		$this->assertFalse( $this->state->step_five_complete() );
	}

	public function test_fully_complete_setup_reports_complete(): void {
		$bot = $this->bots->create( 'My Bot', '123456789:validated-token' );
		$this->bots->update_telegram_identity( $bot->id(), 111, 'my_bot' );
		$this->destinations->create( $bot->id(), DestinationKind::SUPERGROUP, '-1001234567890', null, 'Website Support' );
		$this->bots->mark_registered( $bot->id() );
		update_option( Settings::OPTION_NAME, $this->settings->sanitize( array_merge( $this->settings->get(), array( 'chat_widget_enabled' => true ) ) ) );

		$this->assertTrue( $this->state->step_one_complete() );
		$this->assertTrue( $this->state->step_four_complete() );
		$this->assertTrue( $this->state->step_five_complete() );
		$this->assertSame( 5, $this->state->current_step() );
		$this->assertTrue( $this->state->is_complete() );
	}

	public function test_multiple_bots_always_reads_the_first_created_bot(): void {
		$first  = $this->bots->create( 'First Bot', '111:token' );
		$second = $this->bots->create( 'Second Bot', '222:token' );
		$this->bots->update_telegram_identity( $second->id(), 222, 'second_bot' );

		// Only the second bot is validated, but the wizard must still be
		// evaluated against the first (default) bot, so step 1 stays incomplete.
		$this->assertSame( $first->id(), $this->state->default_bot()->id() );
		$this->assertFalse( $this->state->step_one_complete() );
	}

	public function test_connected_destination_resolves_the_eligible_destination_row(): void {
		$bot = $this->bots->create( 'My Bot', '123456789:validated-token' );
		$this->bots->update_telegram_identity( $bot->id(), 111, 'my_bot' );
		$destination = $this->destinations->create( $bot->id(), DestinationKind::SUPERGROUP, '-1001234567890', null, 'Website Support' );

		$connected = $this->state->connected_destination();

		$this->assertNotNull( $connected );
		$this->assertSame( $destination->id(), $connected->id() );
	}

	public function test_connected_destination_is_null_when_no_bot_or_no_eligible_destination(): void {
		$this->assertNull( $this->state->connected_destination() );

		$bot = $this->bots->create( 'My Bot', '123456789:validated-token' );
		$this->bots->update_telegram_identity( $bot->id(), 111, 'my_bot' );
		$this->destinations->create( $bot->id(), DestinationKind::PRIVATE, '12345', null, 'Website Support' );

		$this->assertNull( $this->state->connected_destination() );
	}

	public function test_derivation_never_writes_any_state(): void {
		global $wpdb;

		$bot = $this->bots->create( 'My Bot', '123456789:validated-token' );
		$this->bots->update_telegram_identity( $bot->id(), 111, 'my_bot' );

		$before_bot_row      = $this->bots->find( $bot->id() );
		$destinations_before = count( $this->destinations->for_bot( $bot->id() ) );

		$this->state->step_one_complete();
		$this->state->step_four_complete();
		$this->state->step_five_complete();
		$this->state->current_step();
		$this->state->is_complete();

		$after_bot_row      = $this->bots->find( $bot->id() );
		$destinations_after = count( $this->destinations->for_bot( $bot->id() ) );

		$this->assertEquals( $before_bot_row, $after_bot_row );
		$this->assertSame( $destinations_before, $destinations_after );
	}
}
