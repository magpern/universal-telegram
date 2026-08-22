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

	public function test_no_bot_configured_has_no_default_bot(): void {
		$this->assertNull( $this->state->default_bot() );
	}

	public function test_bot_created_but_token_never_validated_is_step_one_incomplete(): void {
		$bot = $this->bots->create( 'My Bot', '123456789:unvalidated-token' );

		$this->assertFalse( $this->state->step_one_complete( $bot ) );
		$this->assertSame( 1, $this->state->current_step( $bot ) );
	}

	public function test_bot_validated_with_no_destination_is_step_four_incomplete(): void {
		$bot = $this->bots->create( 'My Bot', '123456789:validated-token' );
		$this->bots->update_telegram_identity( $bot->id(), 111, 'my_bot' );
		$bot = $this->bots->find( $bot->id() );

		$this->assertTrue( $this->state->step_one_complete( $bot ) );
		$this->assertFalse( $this->state->step_four_complete( $bot ) );
		$this->assertSame( 4, $this->state->current_step( $bot ) );
	}

	public function test_destination_wrong_kind_is_step_four_incomplete(): void {
		$bot = $this->bots->create( 'My Bot', '123456789:validated-token' );
		$this->bots->update_telegram_identity( $bot->id(), 111, 'my_bot' );
		$bot = $this->bots->find( $bot->id() );
		$this->destinations->create( $bot->id(), DestinationKind::PRIVATE, '12345', null, 'Website Support' );

		$this->assertFalse( $this->state->step_four_complete( $bot ) );
	}

	public function test_destination_with_topic_id_is_step_four_incomplete(): void {
		$bot = $this->bots->create( 'My Bot', '123456789:validated-token' );
		$this->bots->update_telegram_identity( $bot->id(), 111, 'my_bot' );
		$bot = $this->bots->find( $bot->id() );
		$this->destinations->create( $bot->id(), DestinationKind::SUPERGROUP, '-1001234567890', 42, 'Website Support' );

		$this->assertFalse( $this->state->step_four_complete( $bot ) );
	}

	public function test_destination_correct_but_webhook_unregistered_is_step_five_incomplete(): void {
		$bot = $this->bots->create( 'My Bot', '123456789:validated-token' );
		$this->bots->update_telegram_identity( $bot->id(), 111, 'my_bot' );
		$bot = $this->bots->find( $bot->id() );
		$this->destinations->create( $bot->id(), DestinationKind::SUPERGROUP, '-1001234567890', null, 'Website Support' );

		$this->assertTrue( $this->state->step_four_complete( $bot ) );
		$this->assertFalse( $this->state->step_five_complete( $bot ) );
		$this->assertSame( 5, $this->state->current_step( $bot ) );
	}

	public function test_webhook_uncertain_is_step_five_incomplete(): void {
		$bot = $this->bots->create( 'My Bot', '123456789:validated-token' );
		$this->bots->update_telegram_identity( $bot->id(), 111, 'my_bot' );
		$bot = $this->bots->find( $bot->id() );
		$this->destinations->create( $bot->id(), DestinationKind::SUPERGROUP, '-1001234567890', null, 'Website Support' );
		$this->bots->mark_uncertain( $bot->id() );
		$bot = $this->bots->find( $bot->id() );

		$this->assertFalse( $this->state->step_five_complete( $bot ) );
	}

	public function test_webhook_registered_but_widget_disabled_is_step_five_incomplete_for_the_default_bot(): void {
		$bot = $this->bots->create( 'My Bot', '123456789:validated-token' );
		$this->bots->update_telegram_identity( $bot->id(), 111, 'my_bot' );
		$bot = $this->bots->find( $bot->id() );
		$this->destinations->create( $bot->id(), DestinationKind::SUPERGROUP, '-1001234567890', null, 'Website Support' );
		$this->bots->mark_registered( $bot->id() );
		$bot = $this->bots->find( $bot->id() );

		// chat_widget_enabled left at its default (false). This bot is the
		// only (and therefore default) bot, so the widget check applies.
		$this->assertTrue( $this->state->is_default_bot( $bot ) );
		$this->assertSame( 'registered', $bot->webhook_registration_state() );
		$this->assertFalse( $this->state->step_five_complete( $bot ) );
	}

	public function test_webhook_registered_is_step_five_complete_for_a_non_default_bot_regardless_of_the_widget(): void {
		$default = $this->bots->create( 'Default Bot', '111:token' );
		$this->bots->update_telegram_identity( $default->id(), 1, 'default_bot' );
		$default = $this->bots->find( $default->id() );

		$other = $this->bots->create( 'Other Bot', '222:token' );
		$this->bots->update_telegram_identity( $other->id(), 2, 'other_bot' );
		$other = $this->bots->find( $other->id() );
		$this->destinations->create( $other->id(), DestinationKind::SUPERGROUP, '-1002345678901', null, 'Website Support' );
		$this->bots->mark_registered( $other->id() );
		$other = $this->bots->find( $other->id() );

		// chat_widget_enabled left at its default (false), and the widget is
		// wired only to $default — neither ever applies to $other, so its
		// step 5 depends only on its own webhook registration.
		$this->assertFalse( $this->state->is_default_bot( $other ) );
		$this->assertTrue( $this->state->step_five_complete( $other ) );
	}

	public function test_fully_complete_setup_reports_complete(): void {
		$bot = $this->bots->create( 'My Bot', '123456789:validated-token' );
		$this->bots->update_telegram_identity( $bot->id(), 111, 'my_bot' );
		$bot = $this->bots->find( $bot->id() );
		$this->destinations->create( $bot->id(), DestinationKind::SUPERGROUP, '-1001234567890', null, 'Website Support' );
		$this->bots->mark_registered( $bot->id() );
		$bot = $this->bots->find( $bot->id() );
		update_option( Settings::OPTION_NAME, $this->settings->sanitize( array_merge( $this->settings->get(), array( 'chat_widget_enabled' => true ) ) ) );

		$this->assertTrue( $this->state->step_one_complete( $bot ) );
		$this->assertTrue( $this->state->step_four_complete( $bot ) );
		$this->assertTrue( $this->state->step_five_complete( $bot ) );
		$this->assertSame( 5, $this->state->current_step( $bot ) );
		$this->assertTrue( $this->state->is_complete( $bot ) );
	}

	public function test_default_bot_is_the_first_created_bot_and_is_unaffected_by_which_bot_is_configured(): void {
		$first  = $this->bots->create( 'First Bot', '111:token' );
		$second = $this->bots->create( 'Second Bot', '222:token' );
		$this->bots->update_telegram_identity( $second->id(), 222, 'second_bot' );
		$second = $this->bots->find( $second->id() );

		$this->assertSame( $first->id(), $this->state->default_bot()->id() );
		$this->assertTrue( $this->state->is_default_bot( $first ) );
		$this->assertFalse( $this->state->is_default_bot( $second ) );

		// Configuring the second (non-default) bot through the wizard's own
		// completion checks never changes which bot is the default one.
		$this->state->step_one_complete( $second );
		$this->assertSame( $first->id(), $this->state->default_bot()->id() );
	}

	public function test_connected_destination_resolves_the_eligible_destination_row(): void {
		$bot = $this->bots->create( 'My Bot', '123456789:validated-token' );
		$this->bots->update_telegram_identity( $bot->id(), 111, 'my_bot' );
		$bot         = $this->bots->find( $bot->id() );
		$destination = $this->destinations->create( $bot->id(), DestinationKind::SUPERGROUP, '-1001234567890', null, 'Website Support' );

		$connected = $this->state->connected_destination( $bot );

		$this->assertNotNull( $connected );
		$this->assertSame( $destination->id(), $connected->id() );
	}

	public function test_connected_destination_is_null_when_no_eligible_destination(): void {
		$bot = $this->bots->create( 'My Bot', '123456789:validated-token' );
		$this->bots->update_telegram_identity( $bot->id(), 111, 'my_bot' );
		$bot = $this->bots->find( $bot->id() );
		$this->destinations->create( $bot->id(), DestinationKind::PRIVATE, '12345', null, 'Website Support' );

		$this->assertNull( $this->state->connected_destination( $bot ) );
	}

	public function test_derivation_never_writes_any_state(): void {
		$bot = $this->bots->create( 'My Bot', '123456789:validated-token' );
		$this->bots->update_telegram_identity( $bot->id(), 111, 'my_bot' );
		$bot = $this->bots->find( $bot->id() );

		$before_bot_row      = $this->bots->find( $bot->id() );
		$destinations_before = count( $this->destinations->for_bot( $bot->id() ) );

		$this->state->step_one_complete( $bot );
		$this->state->step_four_complete( $bot );
		$this->state->step_five_complete( $bot );
		$this->state->current_step( $bot );
		$this->state->is_complete( $bot );

		$after_bot_row      = $this->bots->find( $bot->id() );
		$destinations_after = count( $this->destinations->for_bot( $bot->id() ) );

		$this->assertEquals( $before_bot_row, $after_bot_row );
		$this->assertSame( $destinations_before, $destinations_after );
	}
}
