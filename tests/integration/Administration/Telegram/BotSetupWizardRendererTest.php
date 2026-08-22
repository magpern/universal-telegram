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

		$chat_profiles  = new ChatProfileResolver( $this->bots, $this->destinations );
		$state          = new BotSetupWizardState( $chat_profiles, new ChatWidgetAvailability( $settings, $chat_profiles ), $this->destinations );
		$this->renderer = new BotSetupWizardRenderer( $state, new TelegramFormFields(), $this->bots );
	}

	private function render( int $step, ?string $bot_mode = null, ?int $bot_id = null ): string {
		ob_start();
		$this->renderer->render( $step, $bot_mode, $bot_id );

		return ob_get_clean();
	}

	public function test_no_selection_shows_the_landing_choice(): void {
		$html = $this->render( 1 );

		$this->assertStringContainsString( 'How would you like to start?', $html );
		$this->assertStringContainsString( 'Create and set up a new bot', $html );
		$this->assertStringNotContainsString( 'Configure an existing bot', $html );
		$this->assertStringNotContainsString( 'name="name"', $html );
	}

	public function test_landing_choice_offers_configure_existing_only_when_a_bot_exists(): void {
		$this->bots->create( 'My Bot', '123:token' );

		$html = $this->render( 1 );

		$this->assertStringContainsString( 'Configure an existing bot', $html );
	}

	public function test_bot_mode_new_shows_the_botfather_walkthrough_and_the_form_with_a_from_wizard_marker(): void {
		$html = $this->render( 1, 'new' );

		$this->assertStringContainsString( 'Open BotFather in Telegram, run /newbot', $html );
		$this->assertStringContainsString( 'Choose a different way to start', $html );
		$this->assertStringContainsString( 'name="from_wizard" value="1"', $html );
		$this->assertSame( 1, substr_count( $html, 'name="name"' ) );
	}

	public function test_bot_mode_existing_with_no_bots_offers_to_create_one_instead(): void {
		$html = $this->render( 1, 'existing' );

		$this->assertStringContainsString( 'You don', $html );
		$this->assertStringContainsString( 'Create and set up a new bot', $html );
	}

	public function test_bot_mode_existing_with_one_bot_links_straight_into_its_checklist(): void {
		$bot = $this->bots->create( 'Only Bot', '123:token' );

		$html = $this->render( 1, 'existing' );

		$this->assertStringContainsString( 'Only Bot', $html );
		$this->assertStringContainsString( 'bot_id=' . $bot->id(), $html );
	}

	public function test_bot_mode_existing_with_multiple_bots_shows_a_picker(): void {
		$first  = $this->bots->create( 'First Bot', '111:token' );
		$second = $this->bots->create( 'Second Bot', '222:token' );

		$html = $this->render( 1, 'existing' );

		$this->assertStringContainsString( 'Choose a bot to configure:', $html );
		$this->assertStringContainsString( 'First Bot', $html );
		$this->assertStringContainsString( 'Second Bot', $html );
		$this->assertStringContainsString( 'bot_id=' . $first->id(), $html );
		$this->assertStringContainsString( 'bot_id=' . $second->id(), $html );
	}

	public function test_selected_bot_step_one_shows_a_confirmation_never_a_create_bot_form(): void {
		$bot = $this->bots->create( 'My Bot', '123:token' );
		$this->bots->update_telegram_identity( $bot->id(), 1, 'my_bot' );

		$html = $this->render( 1, null, $bot->id() );

		$this->assertStringContainsString( 'Bot created and its token validated.', $html );
		$this->assertStringNotContainsString( 'name="name"', $html );
		$this->assertStringNotContainsString( 'How would you like to start?', $html );
	}

	public function test_progress_nav_marks_the_correct_step_as_current(): void {
		$bot = $this->bots->create( 'My Bot', '123:token' );

		$html = $this->render( 4, null, $bot->id() );

		$this->assertStringContainsString( 'aria-current="step"', $html );
		$this->assertSame( 1, substr_count( $html, 'aria-current="step"' ) );

		$position_of_step_4_link = strpos( $html, 'step=4' );
		$position_of_aria        = strpos( $html, 'aria-current="step"' );
		$this->assertLessThan( $position_of_aria, $position_of_step_4_link );
	}

	public function test_steps_two_and_three_never_render_a_complete_badge(): void {
		$bot = $this->bots->create( 'My Bot', '123:token' );
		$this->bots->update_telegram_identity( $bot->id(), 1, 'my_bot' );
		$this->destinations->create( $bot->id(), DestinationKind::SUPERGROUP, '-100123', null, 'Website Support' );
		$this->bots->mark_registered( $bot->id() );
		update_option( Settings::OPTION_NAME, ( new Settings() )->sanitize( array_merge( ( new Settings() )->get(), array( 'chat_widget_enabled' => true ) ) ) );

		foreach ( array( 2, 3 ) as $step ) {
			$html = $this->render( $step, null, $bot->id() );
			$this->assertStringContainsString( 'Manual step', $html );
		}

		$html = $this->render( 1, null, $bot->id() );
		$this->assertStringContainsString( 'Manual step — do this in Telegram', $html );
	}

	public function test_step_four_shows_test_message_action_only_once_a_destination_exists(): void {
		$bot = $this->bots->create( 'My Bot', '123:token' );
		$this->bots->update_telegram_identity( $bot->id(), 1, 'my_bot' );

		$html_before = $this->render( 4, null, $bot->id() );
		$this->assertStringNotContainsString( 'Send test message', $html_before );

		$this->destinations->create( $bot->id(), DestinationKind::SUPERGROUP, '-100123', null, 'Website Support' );

		$html_after = $this->render( 4, null, $bot->id() );
		$this->assertStringContainsString( 'Send test message', $html_after );
		$this->assertStringContainsString( 'queued', $html_after );
	}

	public function test_step_five_for_the_default_bot_links_to_settings_and_never_renders_a_settings_form(): void {
		$bot = $this->bots->create( 'My Bot', '123:token' );
		$this->bots->update_telegram_identity( $bot->id(), 1, 'my_bot' );

		$html = $this->render( 5, null, $bot->id() );

		$this->assertStringContainsString( 'tab=settings', $html );
		$this->assertStringNotContainsString( 'chat_widget_enabled', $html );
		$this->assertStringNotContainsString( 'remove_data_on_uninstall', $html );
	}

	public function test_step_five_for_a_non_default_bot_skips_the_widget_and_settings_link(): void {
		$default = $this->bots->create( 'Default Bot', '111:token' );
		$other   = $this->bots->create( 'Other Bot', '222:token' );

		$html = $this->render( 5, null, $other->id() );

		$this->assertStringContainsString( 'This bot is not connected to the website', $html );
		$this->assertStringNotContainsString( 'tab=settings', $html );
	}

	public function test_selected_bot_shows_the_non_default_notice_only_when_not_the_default_bot(): void {
		$default = $this->bots->create( 'Default Bot', '111:token' );
		$other   = $this->bots->create( 'Other Bot', '222:token' );

		$default_html = $this->render( 1, null, $default->id() );
		$this->assertStringNotContainsString( 'This is not your website', $default_html );

		$other_html = $this->render( 1, null, $other->id() );
		$this->assertStringContainsString( 'This is not your website', $other_html );
		$this->assertStringContainsString( 'Default Bot', $other_html );
	}

	public function test_every_step_is_reachable_regardless_of_completion(): void {
		$bot = $this->bots->create( 'My Bot', '123:token' );

		foreach ( range( 1, 5 ) as $step ) {
			$html = $this->render( $step, null, $bot->id() );
			$this->assertStringContainsString( 'step=' . $step, $html );
		}
	}

	public function test_named_selected_bot_heading_is_present(): void {
		$bot = $this->bots->create( 'My Named Bot', '123:token' );

		$html = $this->render( 1, null, $bot->id() );

		$this->assertStringContainsString( 'My Named Bot', $html );
	}

	public function test_multiple_bots_shows_a_choose_a_different_bot_link_once_one_is_selected(): void {
		$first = $this->bots->create( 'First Bot', '123:token' );

		$html_one_bot = $this->render( 1, null, $first->id() );
		$this->assertStringNotContainsString( 'Choose a different bot', $html_one_bot );

		$this->bots->create( 'Second Bot', '456:token' );

		$html_two_bots = $this->render( 1, null, $first->id() );
		$this->assertStringContainsString( 'Choose a different bot', $html_two_bots );
	}

	public function test_progress_nav_uses_a_real_ordered_list_and_labelled_nav_landmark(): void {
		$bot = $this->bots->create( 'My Bot', '123:token' );

		$html = $this->render( 1, null, $bot->id() );

		$this->assertStringContainsString( '<nav aria-label="Setup progress"><ol>', $html );
	}

	public function test_step_heading_has_a_focus_target_and_the_skip_link_points_to_it(): void {
		$bot = $this->bots->create( 'My Bot', '123:token' );

		$html = $this->render( 2, null, $bot->id() );

		$this->assertStringContainsString( 'id="wizard-current-step" tabindex="-1"', $html );
		$this->assertStringContainsString( 'aria-labelledby="wizard-current-step"', $html );
		$this->assertStringContainsString( '<a href="#wizard-current-step">Skip to current step</a>', $html );
	}

	public function test_aria_current_never_appears_more_than_once_per_render(): void {
		$bot = $this->bots->create( 'My Bot', '123:token' );

		foreach ( range( 1, 5 ) as $step ) {
			$html = $this->render( $step, null, $bot->id() );
			$this->assertSame( 1, substr_count( $html, 'aria-current="step"' ) );
		}
	}

	public function test_no_token_or_ciphertext_ever_appears_in_output(): void {
		$known_plaintext_token = '123456789:AAH_a-known-synthetic-token-value';
		$bot                   = $this->bots->create( 'My Bot', $known_plaintext_token );

		$renders = array_merge(
			array( $this->render( 1 ), $this->render( 1, 'new' ), $this->render( 1, 'existing' ) ),
			array_map( fn ( int $step ) => $this->render( $step, null, $bot->id() ), range( 1, 5 ) )
		);

		foreach ( $renders as $html ) {
			$this->assertStringNotContainsString( $known_plaintext_token, $html );
			$this->assertStringNotContainsString( $bot->token_ciphertext(), $html );
			$this->assertStringNotContainsString( $bot->webhook_secret_ciphertext(), $html );
		}
	}
}
