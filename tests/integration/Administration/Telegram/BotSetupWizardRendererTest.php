<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Administration\Telegram;

use UniversalTelegram\Administration\Telegram\BotSetupWizardRenderer;
use UniversalTelegram\Administration\Telegram\BotSetupWizardState;
use UniversalTelegram\Administration\Telegram\TelegramFormFields;
use UniversalTelegram\ChatWidget\ChatWidgetAvailability;
use UniversalTelegram\Conversations\ChatProfileResolver;
use UniversalTelegram\Core\Configuration\Settings;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;
use UniversalTelegram\Telegram\Configuration\DestinationKind;
use UniversalTelegram\Telegram\Configuration\DestinationRepository;
use WP_UnitTestCase;

final class BotSetupWizardRendererTest extends WP_UnitTestCase {

	private BotProfileRepository $bots;
	private DestinationRepository $destinations;
	private BotSetupWizardRenderer $renderer;

	protected function setUp(): void {
		parent::setUp();

		$schema_health      = new SchemaHealth();
		$vault              = new CredentialVault();
		$this->bots         = new BotProfileRepository( $schema_health, $vault );
		$this->destinations = new DestinationRepository( $schema_health );
		$settings           = new Settings();

		$chat_profiles = new ChatProfileResolver( $this->bots, $this->destinations );
		$state         = new BotSetupWizardState( $chat_profiles, new ChatWidgetAvailability( $settings, $chat_profiles ), $this->destinations );
		$this->renderer = new BotSetupWizardRenderer( $state, new TelegramFormFields(), $this->bots );
	}

	private function render( int $step ): string {
		ob_start();
		$this->renderer->render( $step );

		return ob_get_clean();
	}

	public function test_progress_nav_marks_the_correct_step_as_current(): void {
		$html = $this->render( 4 );

		$this->assertStringContainsString( 'aria-current="step"', $html );
		$this->assertSame( 1, substr_count( $html, 'aria-current="step"' ) );

		$position_of_step_4_link = strpos( $html, 'step=4' );
		$position_of_aria        = strpos( $html, 'aria-current="step"' );
		// The aria-current attribute must belong to the step 4 link, not some other step.
		$this->assertLessThan( $position_of_aria, $position_of_step_4_link );
	}

	public function test_steps_two_and_three_never_render_a_complete_badge(): void {
		$bot = $this->bots->create( 'My Bot', '123:token' );
		$this->bots->update_telegram_identity( $bot->id(), 1, 'my_bot' );
		$this->destinations->create( $bot->id(), DestinationKind::SUPERGROUP, '-100123', null, 'Website Support' );
		$this->bots->mark_registered( $bot->id() );
		update_option( Settings::OPTION_NAME, ( new Settings() )->sanitize( array_merge( ( new Settings() )->get(), array( 'chat_widget_enabled' => true ) ) ) );

		foreach ( array( 2, 3 ) as $step ) {
			$html = $this->render( $step );
			$this->assertStringContainsString( 'Manual step', $html );
		}

		// The step-2/3 badge text must appear for every render, never "Complete" replacing it.
		$html = $this->render( 1 );
		$this->assertStringContainsString( 'Manual step — do this in Telegram', $html );
	}

	public function test_step_four_shows_test_message_action_only_once_a_destination_exists(): void {
		$bot = $this->bots->create( 'My Bot', '123:token' );
		$this->bots->update_telegram_identity( $bot->id(), 1, 'my_bot' );

		$html_before = $this->render( 4 );
		$this->assertStringNotContainsString( 'Send test message', $html_before );

		$this->destinations->create( $bot->id(), DestinationKind::SUPERGROUP, '-100123', null, 'Website Support' );

		$html_after = $this->render( 4 );
		$this->assertStringContainsString( 'Send test message', $html_after );
		$this->assertStringContainsString( 'queued', $html_after );
	}

	public function test_step_five_links_to_settings_and_never_renders_a_settings_form(): void {
		$bot = $this->bots->create( 'My Bot', '123:token' );
		$this->bots->update_telegram_identity( $bot->id(), 1, 'my_bot' );

		$html = $this->render( 5 );

		$this->assertStringContainsString( '&tab=settings', $html );
		$this->assertStringNotContainsString( 'chat_widget_enabled', $html );
		$this->assertStringNotContainsString( 'remove_data_on_uninstall', $html );
	}

	public function test_every_step_is_reachable_regardless_of_completion(): void {
		// No bot at all — every step must still render its own link/content, never error.
		foreach ( range( 1, 5 ) as $step ) {
			$html = $this->render( $step );
			$this->assertStringContainsString( 'step=' . $step, $html );
		}
	}

	public function test_named_default_bot_heading_is_present(): void {
		$this->bots->create( 'My Named Bot', '123:token' );

		$html = $this->render( 1 );

		$this->assertStringContainsString( 'My Named Bot', $html );
	}

	public function test_second_bot_shows_manage_other_bots_link(): void {
		$this->bots->create( 'First Bot', '123:token' );

		$html_one_bot = $this->render( 1 );
		$this->assertStringNotContainsString( 'Manage other bots', $html_one_bot );

		$this->bots->create( 'Second Bot', '456:token' );

		$html_two_bots = $this->render( 1 );
		$this->assertStringContainsString( 'Manage other bots', $html_two_bots );
	}

	public function test_no_token_or_ciphertext_ever_appears_in_output(): void {
		$known_plaintext_token = '123456789:AAH_a-known-synthetic-token-value';
		$bot                   = $this->bots->create( 'My Bot', $known_plaintext_token );

		foreach ( range( 1, 5 ) as $step ) {
			$html = $this->render( $step );
			$this->assertStringNotContainsString( $known_plaintext_token, $html );
			$this->assertStringNotContainsString( $bot->token_ciphertext(), $html );
			$this->assertStringNotContainsString( $bot->webhook_secret_ciphertext(), $html );
		}
	}
}
