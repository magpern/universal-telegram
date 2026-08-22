<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Administration\Telegram;

use UniversalTelegram\Administration\Telegram\BotManagementPage;
use UniversalTelegram\Administration\Telegram\BotSetupWizardRenderer;
use UniversalTelegram\Administration\Telegram\BotSetupWizardState;
use UniversalTelegram\ChatWidget\ChatWidgetAvailability;
use UniversalTelegram\Administration\Telegram\TelegramFormFields;
use UniversalTelegram\Conversations\ChatProfileResolver;
use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;
use UniversalTelegram\Core\Configuration\Settings;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;
use UniversalTelegram\Telegram\Configuration\DestinationRepository;
use UniversalTelegram\Telegram\Inbound\UpdateRepository;
use UniversalTelegram\Telegram\Outbound\OutboundMessageRepository;
use WP_UnitTestCase;

final class BotManagementPageTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		( new CapabilityRegistrar() )->grant_to_administrator();

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
	}

	/**
	 * Builds a fully-wired BotManagementPage against real, freshly
	 * constructed collaborators (this codebase's existing testing
	 * convention — no mocking framework, real objects against the test DB).
	 *
	 * @param BotProfileRepository  $bots         Bot profiles.
	 * @param DestinationRepository $destinations Destinations.
	 *
	 * @return BotManagementPage
	 */
	private function make_page( BotProfileRepository $bots, DestinationRepository $destinations ): BotManagementPage {
		$schema_health = new SchemaHealth();
		$vault         = new CredentialVault();
		$settings      = new Settings();

		$chat_profiles = new ChatProfileResolver( $bots, $destinations );
		$wizard_state  = new BotSetupWizardState(
			$chat_profiles,
			new ChatWidgetAvailability( $settings, $chat_profiles ),
			$destinations
		);
		$forms         = new TelegramFormFields();

		return new BotManagementPage(
			$bots,
			$destinations,
			new UpdateRepository( $schema_health ),
			new OutboundMessageRepository( $schema_health, $vault ),
			$forms,
			$wizard_state,
			new BotSetupWizardRenderer( $wizard_state, $forms, $bots )
		);
	}

	public function test_the_rendered_page_never_exposes_the_plaintext_token_or_any_ciphertext(): void {
		$schema_health = new SchemaHealth();
		$vault         = new CredentialVault();

		$bots         = new BotProfileRepository( $schema_health, $vault );
		$destinations = new DestinationRepository( $schema_health );

		$known_plaintext_token = '123456789:AAH_a-known-synthetic-token-value';
		$bot                   = $bots->create( 'My Bot', $known_plaintext_token );

		$page = $this->make_page( $bots, $destinations );

		ob_start();
		$page->render_tab_content();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'My Bot', $html );
		$this->assertStringNotContainsString( $known_plaintext_token, $html );
		$this->assertStringNotContainsString( $bot->token_ciphertext(), $html );
		$this->assertStringNotContainsString( $bot->webhook_secret_ciphertext(), $html );
	}

	public function test_incomplete_setup_on_default_url_renders_wizard_only(): void {
		$schema_health = new SchemaHealth();
		$vault         = new CredentialVault();

		$page = $this->make_page(
			new BotProfileRepository( $schema_health, $vault ),
			new DestinationRepository( $schema_health )
		);

		ob_start();
		$page->render_tab_content();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Set up your Telegram bot', $html );
		$this->assertStringContainsString( '1. Create bot', $html );
		$this->assertStringContainsString( '2. Create support group', $html );
		$this->assertStringContainsString( '3. Add bot as administrator', $html );
		$this->assertStringContainsString( '4. Connect group', $html );
		$this->assertStringContainsString( '5. Activate chat widget', $html );

		$this->assertStringContainsString( 'href="https://core.telegram.org/bots#6-botfather"', $html );
		$this->assertStringContainsString( 'target="_blank" rel="noopener noreferrer"', $html );
		$this->assertStringContainsString( 'Keep your bot token private. Anyone with it can control the bot.', $html );

		// Exactly one create-bot form (the wizard's own, in step 1) — the
		// manual "Add a bot" heading/form must not be appended beneath it.
		$this->assertStringNotContainsString( '<h2>Add a bot</h2>', $html );
		$this->assertSame( 1, substr_count( $html, 'name="name"' ) );
		$this->assertSame( 1, substr_count( $html, 'name="token"' ) );
	}

	public function test_complete_setup_defaults_to_the_manual_view_with_a_setup_wizard_link(): void {
		$schema_health = new SchemaHealth();
		$vault         = new CredentialVault();
		$bots          = new BotProfileRepository( $schema_health, $vault );
		$destinations  = new DestinationRepository( $schema_health );

		$bot = $bots->create( 'My Bot', '123:token' );
		$bots->update_telegram_identity( $bot->id(), 1, 'my_bot' );
		$destinations->create( $bot->id(), \UniversalTelegram\Telegram\Configuration\DestinationKind::SUPERGROUP, '-100123', null, 'Website Support' );
		$bots->mark_registered( $bot->id() );
		$settings = new Settings();
		update_option( Settings::OPTION_NAME, $settings->sanitize( array_merge( $settings->get(), array( 'chat_widget_enabled' => true ) ) ) );

		$page = $this->make_page( $bots, $destinations );

		ob_start();
		$page->render_tab_content();
		$html = ob_get_clean();

		// Manual view only: the wizard's own heading/nav/step-1 copy must not appear at all.
		$this->assertStringNotContainsString( 'Set up your Telegram bot', $html );
		$this->assertStringNotContainsString( 'aria-label="Setup progress"', $html );
		$this->assertStringNotContainsString( 'Open BotFather in Telegram, run /newbot', $html );
		$this->assertStringContainsString( 'Setup wizard', $html );

		// The manual "Add a bot" form is present, exactly once — not duplicated
		// by any wizard-rendered create-bot form. The bot's own "Replace
		// token" action form also has a name="token" field, so "name=\"name\""
		// — unique to the create-bot form — is the reliable duplicate check.
		$this->assertStringContainsString( '<h2>Add a bot</h2>', $html );
		$this->assertSame( 1, substr_count( $html, 'name="name"' ) );
	}

	public function test_view_wizard_query_arg_forces_the_wizard_even_when_setup_is_complete(): void {
		$schema_health = new SchemaHealth();
		$vault         = new CredentialVault();
		$bots          = new BotProfileRepository( $schema_health, $vault );
		$destinations  = new DestinationRepository( $schema_health );

		$bot = $bots->create( 'My Bot', '123:token' );
		$bots->update_telegram_identity( $bot->id(), 1, 'my_bot' );
		$destinations->create( $bot->id(), \UniversalTelegram\Telegram\Configuration\DestinationKind::SUPERGROUP, '-100123', null, 'Website Support' );
		$bots->mark_registered( $bot->id() );
		$settings = new Settings();
		update_option( Settings::OPTION_NAME, $settings->sanitize( array_merge( $settings->get(), array( 'chat_widget_enabled' => true ) ) ) );

		$page = $this->make_page( $bots, $destinations );

		// Explicit step 1, where the wizard's own create-bot form is shown —
		// the step at which the duplicate-form defect could otherwise recur.
		$_GET['view'] = 'wizard';
		$_GET['step'] = '1';
		ob_start();
		$page->render_tab_content();
		$html = ob_get_clean();
		unset( $_GET['view'], $_GET['step'] );

		$this->assertStringContainsString( 'Set up your Telegram bot', $html );

		// The explicit wizard view must never append the manual bot list or
		// "Add a bot" form beneath it, even though setup is complete.
		$this->assertStringNotContainsString( '<h2>Add a bot</h2>', $html );
		$this->assertStringNotContainsString( 'Adding another bot here', $html );
		$this->assertSame( 1, substr_count( $html, 'name="name"' ) );
	}

	public function test_view_wizard_and_step_three_always_renders_step_three(): void {
		$schema_health = new SchemaHealth();
		$vault         = new CredentialVault();
		$page          = $this->make_page(
			new BotProfileRepository( $schema_health, $vault ),
			new DestinationRepository( $schema_health )
		);

		$_GET['view'] = 'wizard';
		$_GET['step'] = '3';
		ob_start();
		$page->render_tab_content();
		$html = ob_get_clean();
		unset( $_GET['view'], $_GET['step'] );

		$this->assertStringContainsString( 'aria-current="step"', $html );
		$position_of_step_3_link = strpos( $html, 'step=3' );
		$position_of_aria        = strpos( $html, 'aria-current="step"' );
		$this->assertLessThan( $position_of_aria, $position_of_step_3_link );
	}

	public function test_an_unrecognized_view_value_behaves_identically_to_no_view_at_all(): void {
		$schema_health = new SchemaHealth();
		$vault         = new CredentialVault();
		$page          = $this->make_page(
			new BotProfileRepository( $schema_health, $vault ),
			new DestinationRepository( $schema_health )
		);

		$_GET['view'] = 'something_else';
		ob_start();
		$page->render_tab_content();
		$html_unrecognized = ob_get_clean();
		unset( $_GET['view'] );

		ob_start();
		$page->render_tab_content();
		$html_absent = ob_get_clean();

		// No bot configured either way, so both must fall back to the wizard's default step 1.
		$this->assertStringContainsString( 'Set up your Telegram bot', $html_unrecognized );
		$this->assertStringContainsString( 'Set up your Telegram bot', $html_absent );
	}

	/**
	 * @dataProvider invalid_step_values
	 *
	 * @param string $invalid_step The raw, invalid `step` query value.
	 */
	public function test_invalid_step_values_fall_back_to_the_derived_current_step( string $invalid_step ): void {
		$schema_health = new SchemaHealth();
		$vault         = new CredentialVault();
		$page          = $this->make_page(
			new BotProfileRepository( $schema_health, $vault ),
			new DestinationRepository( $schema_health )
		);

		$_GET['view'] = 'wizard';
		$_GET['step'] = $invalid_step;
		ob_start();
		$page->render_tab_content();
		$html = ob_get_clean();
		unset( $_GET['view'], $_GET['step'] );

		// No bot configured, so the derived current step is 1 — never clamped
		// from 0/6, and never an invalid render for "abc".
		$position_of_step_1_link = strpos( $html, 'step=1' );
		$position_of_aria        = strpos( $html, 'aria-current="step"' );
		$this->assertLessThan( $position_of_aria, $position_of_step_1_link );
	}

	public function test_missing_step_falls_back_to_the_derived_current_step(): void {
		$schema_health = new SchemaHealth();
		$vault         = new CredentialVault();
		$page          = $this->make_page(
			new BotProfileRepository( $schema_health, $vault ),
			new DestinationRepository( $schema_health )
		);

		$_GET['view'] = 'wizard';
		ob_start();
		$page->render_tab_content();
		$html = ob_get_clean();
		unset( $_GET['view'] );

		$position_of_step_1_link = strpos( $html, 'step=1' );
		$position_of_aria        = strpos( $html, 'aria-current="step"' );
		$this->assertLessThan( $position_of_aria, $position_of_step_1_link );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function invalid_step_values(): array {
		return array(
			'zero'        => array( '0' ),
			'six'         => array( '6' ),
			'non_numeric' => array( 'abc' ),
		);
	}

	public function test_additional_bot_form_and_its_explanatory_sentence_appear_only_in_manual_view(): void {
		$schema_health = new SchemaHealth();
		$vault         = new CredentialVault();
		$bots          = new BotProfileRepository( $schema_health, $vault );
		$destinations  = new DestinationRepository( $schema_health );

		$bot = $bots->create( 'My Bot', '123:token' );
		$bots->update_telegram_identity( $bot->id(), 1, 'my_bot' );
		$destinations->create( $bot->id(), \UniversalTelegram\Telegram\Configuration\DestinationKind::SUPERGROUP, '-100123', null, 'Website Support' );
		$bots->mark_registered( $bot->id() );
		$settings = new Settings();
		update_option( Settings::OPTION_NAME, $settings->sanitize( array_merge( $settings->get(), array( 'chat_widget_enabled' => true ) ) ) );

		$page = $this->make_page( $bots, $destinations );

		// Manual view (setup complete, no explicit wizard request): the
		// additional-bot form and its explanatory sentence are present.
		ob_start();
		$page->render_tab_content();
		$manual_html = ob_get_clean();

		$this->assertStringContainsString( '<h2>Add a bot</h2>', $manual_html );
		$this->assertStringContainsString( 'Adding another bot here does not change your website', $manual_html );

		// Explicit wizard view: neither the form nor the sentence appear.
		$_GET['view'] = 'wizard';
		ob_start();
		$page->render_tab_content();
		$wizard_html = ob_get_clean();
		unset( $_GET['view'] );

		$this->assertStringNotContainsString( '<h2>Add a bot</h2>', $wizard_html );
		$this->assertStringNotContainsString( 'Adding another bot here does not change your website', $wizard_html );
	}

	public function test_manual_view_manages_multiple_bots_and_preserves_default_bot_semantics(): void {
		$schema_health = new SchemaHealth();
		$vault         = new CredentialVault();
		$bots          = new BotProfileRepository( $schema_health, $vault );
		$destinations  = new DestinationRepository( $schema_health );

		$first = $bots->create( 'First Bot', '111:token' );
		$bots->update_telegram_identity( $first->id(), 1, 'first_bot' );
		$destinations->create( $first->id(), \UniversalTelegram\Telegram\Configuration\DestinationKind::SUPERGROUP, '-100111', null, 'Website Support' );
		$bots->mark_registered( $first->id() );
		$settings = new Settings();
		update_option( Settings::OPTION_NAME, $settings->sanitize( array_merge( $settings->get(), array( 'chat_widget_enabled' => true ) ) ) );

		$second = $bots->create( 'Second Bot', '222:token' );

		$chat_profiles = new ChatProfileResolver( $bots, $destinations );

		// The first-created bot remains the default website chat bot,
		// unaffected by adding a second, incomplete bot — this hotfix does
		// not touch default-bot selection.
		$this->assertSame( $first->id(), $chat_profiles->default_bot()->id() );

		$page = $this->make_page( $bots, $destinations );

		ob_start();
		$page->render_tab_content();
		$html = ob_get_clean();

		// Setup as a whole is still considered complete (based on the
		// default bot only), so the manual view — where both bots are
		// listed and managed — remains the default, unchanged behaviour.
		$this->assertStringNotContainsString( 'Set up your Telegram bot', $html );
		$this->assertStringContainsString( 'First Bot', $html );
		$this->assertStringContainsString( 'Second Bot', $html );
		$this->assertStringContainsString( '<h2>Add a bot</h2>', $html );
	}

	public function test_an_unauthorized_user_is_denied(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$schema_health = new SchemaHealth();
		$vault         = new CredentialVault();

		$page = $this->make_page(
			new BotProfileRepository( $schema_health, $vault ),
			new DestinationRepository( $schema_health )
		);

		$this->expectException( \WPDieException::class );
		$page->render_tab_content();
	}
}
