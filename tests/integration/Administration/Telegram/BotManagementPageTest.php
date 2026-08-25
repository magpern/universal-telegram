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
use UniversalTelegram\Conversations\ConversationRepository;
use UniversalTelegram\Conversations\VisitorTokenGenerator;
use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;
use UniversalTelegram\Core\Configuration\Settings;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;
use UniversalTelegram\Telegram\Configuration\DestinationKind;
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

	protected function tearDown(): void {
		unset( $_GET['view'], $_GET['step'], $_GET['bot_mode'], $_GET['bot_id'] );
		parent::tearDown();
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
			new BotSetupWizardRenderer( $wizard_state, $forms, $bots ),
			new ConversationRepository( $schema_health, $vault, new VisitorTokenGenerator() )
		);
	}

	/**
	 * Marks a bot as fully set up: token validated, connected destination,
	 * webhook registered, and (only if it's the default/first bot) the
	 * chat widget enabled.
	 *
	 * @param BotProfileRepository  $bots         Bot profiles.
	 * @param DestinationRepository $destinations Destinations.
	 * @param int                   $bot_id       The bot's primary key.
	 * @param string                $chat_id      A unique synthetic Telegram chat id.
	 */
	private function complete_setup_for( BotProfileRepository $bots, DestinationRepository $destinations, int $bot_id, string $chat_id ): void {
		$bots->update_telegram_identity( $bot_id, $bot_id, 'bot_' . $bot_id );
		$destinations->create( $bot_id, DestinationKind::SUPERGROUP, $chat_id, null, 'Website Support' );
		$bots->mark_registered( $bot_id );
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

	public function test_bare_url_with_no_bots_at_all_shows_the_wizard_landing_choice(): void {
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
		$this->assertStringContainsString( 'How would you like to start?', $html );
		$this->assertStringContainsString( 'Create and set up a new bot', $html );
		$this->assertStringNotContainsString( 'Configure an existing bot', $html );
		$this->assertStringNotContainsString( 'name="name"', $html );
		$this->assertStringNotContainsString( '<h2>Add a bot</h2>', $html );
	}

	public function test_bare_url_with_an_incomplete_default_bot_auto_resumes_its_own_checklist(): void {
		$schema_health = new SchemaHealth();
		$vault         = new CredentialVault();
		$bots          = new BotProfileRepository( $schema_health, $vault );
		$destinations  = new DestinationRepository( $schema_health );

		$bot = $bots->create( 'My Bot', '123:token' );
		$bots->update_telegram_identity( $bot->id(), 1, 'my_bot' );
		// Steps 2-4 still pending: no destination yet.

		$page = $this->make_page( $bots, $destinations );

		ob_start();
		$page->render_tab_content();
		$html = ob_get_clean();

		// Auto-resumes straight into the bot's own checklist (step 4) —
		// never the top-level landing choice, matching this hotfix's
		// original guided-continuation behaviour for the common
		// single-bot case.
		$this->assertStringNotContainsString( 'How would you like to start?', $html );
		$this->assertStringContainsString( 'Configuring: ', $html );
		$this->assertStringContainsString( 'My Bot', $html );
		$this->assertStringContainsString( 'bot_id=' . $bot->id(), $html );
	}

	public function test_complete_setup_defaults_to_the_manual_view_with_a_setup_wizard_link(): void {
		$schema_health = new SchemaHealth();
		$vault         = new CredentialVault();
		$bots          = new BotProfileRepository( $schema_health, $vault );
		$destinations  = new DestinationRepository( $schema_health );

		$bot = $bots->create( 'My Bot', '123:token' );
		$this->complete_setup_for( $bots, $destinations, $bot->id(), '-100123' );
		$settings = new Settings();
		update_option( Settings::OPTION_NAME, $settings->sanitize( array_merge( $settings->get(), array( 'chat_widget_enabled' => true ) ) ) );

		$page = $this->make_page( $bots, $destinations );

		ob_start();
		$page->render_tab_content();
		$html = ob_get_clean();

		// Manual view only: the wizard's own heading/nav/step-1 copy must not appear at all.
		$this->assertStringNotContainsString( 'Set up your Telegram bot', $html );
		$this->assertStringNotContainsString( 'aria-label="Setup progress"', $html );
		$this->assertStringContainsString( 'Setup wizard', $html );

		// The manual "Add a bot" form is present, exactly once — not duplicated
		// by any wizard-rendered create-bot form. The bot's own "Replace
		// token" action form also has a name="token" field, so "name=\"name\""
		// — unique to the create-bot form — is the reliable duplicate check.
		$this->assertStringContainsString( '<h2>Add a bot</h2>', $html );
		$this->assertSame( 1, substr_count( $html, 'name="name"' ) );
	}

	public function test_explicit_setup_wizard_link_always_shows_the_landing_choice_even_when_complete(): void {
		$schema_health = new SchemaHealth();
		$vault         = new CredentialVault();
		$bots          = new BotProfileRepository( $schema_health, $vault );
		$destinations  = new DestinationRepository( $schema_health );

		$bot = $bots->create( 'My Bot', '123:token' );
		$this->complete_setup_for( $bots, $destinations, $bot->id(), '-100123' );
		$settings = new Settings();
		update_option( Settings::OPTION_NAME, $settings->sanitize( array_merge( $settings->get(), array( 'chat_widget_enabled' => true ) ) ) );

		$page = $this->make_page( $bots, $destinations );

		$_GET['view'] = 'wizard';
		ob_start();
		$page->render_tab_content();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'How would you like to start?', $html );
		$this->assertStringContainsString( 'Configure an existing bot', $html );
		$this->assertStringNotContainsString( '<h2>Add a bot</h2>', $html );
	}

	public function test_explicit_bot_id_and_step_renders_that_bots_checklist_step(): void {
		$schema_health = new SchemaHealth();
		$vault         = new CredentialVault();
		$bots          = new BotProfileRepository( $schema_health, $vault );
		$destinations  = new DestinationRepository( $schema_health );

		$bot = $bots->create( 'My Bot', '123:token' );
		$this->complete_setup_for( $bots, $destinations, $bot->id(), '-100123' );
		$settings = new Settings();
		update_option( Settings::OPTION_NAME, $settings->sanitize( array_merge( $settings->get(), array( 'chat_widget_enabled' => true ) ) ) );

		$page = $this->make_page( $bots, $destinations );

		$_GET['view']   = 'wizard';
		$_GET['bot_id'] = (string) $bot->id();
		$_GET['step']   = '4';
		ob_start();
		$page->render_tab_content();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'aria-current="step"', $html );
		$position_of_step_4_link = strpos( $html, 'step=4' );
		$position_of_aria        = strpos( $html, 'aria-current="step"' );
		$this->assertLessThan( $position_of_aria, $position_of_step_4_link );

		// The explicit wizard view must never append the manual bot list or
		// "Add a bot" form beneath it, even though setup is complete.
		$this->assertStringNotContainsString( '<h2>Add a bot</h2>', $html );
		$this->assertStringNotContainsString( 'Adding another bot here', $html );
	}

	public function test_bot_mode_new_and_existing_are_reachable_from_the_bots_tab(): void {
		$schema_health = new SchemaHealth();
		$vault         = new CredentialVault();

		$page = $this->make_page(
			new BotProfileRepository( $schema_health, $vault ),
			new DestinationRepository( $schema_health )
		);

		$_GET['view']     = 'wizard';
		$_GET['bot_mode'] = 'new';
		ob_start();
		$page->render_tab_content();
		$new_html = ob_get_clean();

		$this->assertStringContainsString( 'Open BotFather in Telegram, run /newbot', $new_html );
		$this->assertSame( 1, substr_count( $new_html, 'name="name"' ) );
	}

	public function test_an_invalid_bot_id_falls_back_to_the_landing_choice(): void {
		$schema_health = new SchemaHealth();
		$vault         = new CredentialVault();

		$page = $this->make_page(
			new BotProfileRepository( $schema_health, $vault ),
			new DestinationRepository( $schema_health )
		);

		$_GET['view']   = 'wizard';
		$_GET['bot_id'] = '999999';
		ob_start();
		$page->render_tab_content();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'How would you like to start?', $html );
	}

	/**
	 * @dataProvider invalid_step_values
	 *
	 * @param string $invalid_step The raw, invalid `step` query value.
	 */
	public function test_invalid_step_values_fall_back_to_the_derived_current_step_for_a_selected_bot( string $invalid_step ): void {
		$schema_health = new SchemaHealth();
		$vault         = new CredentialVault();
		$bots          = new BotProfileRepository( $schema_health, $vault );
		$destinations  = new DestinationRepository( $schema_health );

		$bot = $bots->create( 'My Bot', '123:token' );
		$bots->update_telegram_identity( $bot->id(), 1, 'my_bot' );

		$page = $this->make_page( $bots, $destinations );

		$_GET['view']   = 'wizard';
		$_GET['bot_id'] = (string) $bot->id();
		$_GET['step']   = $invalid_step;
		ob_start();
		$page->render_tab_content();
		$html = ob_get_clean();

		// Step 1 is already complete for any real bot, step 4 is not, so the
		// derived current step is 4 — never clamped from 0/6, never an
		// invalid render for "abc".
		$position_of_step_4_link = strpos( $html, 'step=4' );
		$position_of_aria        = strpos( $html, 'aria-current="step"' );
		$this->assertLessThan( $position_of_aria, $position_of_step_4_link );
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
		$this->complete_setup_for( $bots, $destinations, $bot->id(), '-100123' );
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

		$this->assertStringNotContainsString( '<h2>Add a bot</h2>', $wizard_html );
		$this->assertStringNotContainsString( 'Adding another bot here does not change your website', $wizard_html );
	}

	public function test_manual_view_manages_multiple_bots_and_preserves_default_bot_semantics(): void {
		$schema_health = new SchemaHealth();
		$vault         = new CredentialVault();
		$bots          = new BotProfileRepository( $schema_health, $vault );
		$destinations  = new DestinationRepository( $schema_health );

		$first = $bots->create( 'First Bot', '111:token' );
		$this->complete_setup_for( $bots, $destinations, $first->id(), '-100111' );
		$settings = new Settings();
		update_option( Settings::OPTION_NAME, $settings->sanitize( array_merge( $settings->get(), array( 'chat_widget_enabled' => true ) ) ) );

		$bots->create( 'Second Bot', '222:token' );

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

	public function test_configuring_a_second_bot_through_the_wizard_never_changes_the_default_bot(): void {
		$schema_health = new SchemaHealth();
		$vault         = new CredentialVault();
		$bots          = new BotProfileRepository( $schema_health, $vault );
		$destinations  = new DestinationRepository( $schema_health );

		$first = $bots->create( 'First Bot', '111:token' );
		$this->complete_setup_for( $bots, $destinations, $first->id(), '-100111' );
		$settings = new Settings();
		update_option( Settings::OPTION_NAME, $settings->sanitize( array_merge( $settings->get(), array( 'chat_widget_enabled' => true ) ) ) );

		$second = $bots->create( 'Second Bot', '222:token' );
		$bots->update_telegram_identity( $second->id(), 2, 'second_bot' );

		$page = $this->make_page( $bots, $destinations );

		$_GET['view']   = 'wizard';
		$_GET['bot_id'] = (string) $second->id();
		ob_start();
		$page->render_tab_content();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Second Bot', $html );
		$this->assertStringContainsString( 'This is not your website', $html );
		$this->assertStringContainsString( 'First Bot', $html );

		$chat_profiles = new ChatProfileResolver( $bots, $destinations );
		$this->assertSame( $first->id(), $chat_profiles->default_bot()->id() );
	}

	public function test_a_conversation_created_destination_is_excluded_from_the_manual_table_and_has_no_test_message_action(): void {
		$schema_health = new SchemaHealth();
		$vault         = new CredentialVault();
		$bots          = new BotProfileRepository( $schema_health, $vault );
		$destinations  = new DestinationRepository( $schema_health );
		$conversations = new ConversationRepository( $schema_health, $vault, new VisitorTokenGenerator() );

		$bot = $bots->create( 'Bot', 'token' );
		$this->complete_setup_for( $bots, $destinations, $bot->id(), '-100999' );
		$settings = new Settings();
		update_option( Settings::OPTION_NAME, $settings->sanitize( array_merge( $settings->get(), array( 'chat_widget_enabled' => true ) ) ) );

		$conversation_destination = $destinations->create( $bot->id(), DestinationKind::SUPERGROUP, '-100999', 77, 'Conversation abc123' );
		$conversation             = $conversations->create( 'abc123', 'hash', $bot->id(), null );
		$conversations->set_destination( $conversation->id(), $conversation_destination->id() );

		$page = $this->make_page( $bots, $destinations );

		ob_start();
		$page->render_tab_content();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Conversation topics', $html );
		$this->assertStringContainsString( 'Conversation abc123', $html );
		$this->assertStringContainsString( 'Open conversation', $html );
		$this->assertStringContainsString( 'conversation_id=' . $conversation->id(), $html );

		// The manual "Website Support" destination created by
		// complete_setup_for() still has its test-message action.
		$this->assertSame( 1, substr_count( $html, 'Send test message' ) );
	}

	public function test_destination_hygiene_regression_an_authenticated_owned_conversations_destination_is_also_excluded(): void {
		// M06.3.1 (ADR-0025) regression: the owner_user_id addition must not
		// change destination_ids_for_bot()'s own bot_id/destination_id-only
		// query — an authenticated, owned conversation's topic is hidden
		// from the manual table exactly like a legacy ownerless one.
		$schema_health = new SchemaHealth();
		$vault         = new CredentialVault();
		$bots          = new BotProfileRepository( $schema_health, $vault );
		$destinations  = new DestinationRepository( $schema_health );
		$conversations = new ConversationRepository( $schema_health, $vault, new VisitorTokenGenerator() );

		$bot = $bots->create( 'Bot', 'token' );
		$this->complete_setup_for( $bots, $destinations, $bot->id(), '-100999' );
		$settings = new Settings();
		update_option( Settings::OPTION_NAME, $settings->sanitize( array_merge( $settings->get(), array( 'chat_widget_enabled' => true ) ) ) );

		$conversation_destination = $destinations->create( $bot->id(), DestinationKind::SUPERGROUP, '-100999', 78, 'Conversation owned-abc' );
		$conversation             = $conversations->create( 'owned-abc', 'hash', $bot->id(), null, null, 909, 'Owner' );
		$conversations->set_destination( $conversation->id(), $conversation_destination->id() );

		$page = $this->make_page( $bots, $destinations );

		ob_start();
		$page->render_tab_content();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Conversation topics', $html );
		$this->assertStringContainsString( 'Conversation owned-abc', $html );
		$this->assertSame( 1, substr_count( $html, 'Send test message' ) );
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

	public function test_dead_letter_section_shows_help_text_and_dismiss_action(): void {
		$schema_health = new SchemaHealth();
		$vault         = new CredentialVault();
		$bots          = new BotProfileRepository( $schema_health, $vault );
		$destinations  = new DestinationRepository( $schema_health );
		$messages      = new OutboundMessageRepository( $schema_health, $vault );

		$bot = $bots->create( 'Bot', 'token' );
		$this->complete_setup_for( $bots, $destinations, $bot->id(), '-100123' );
		$settings = new Settings();
		update_option( Settings::OPTION_NAME, $settings->sanitize( array_merge( $settings->get(), array( 'chat_widget_enabled' => true ) ) ) );

		$destination = $destinations->for_bot( $bot->id() )[0];
		$message     = $messages->create( $bot->id(), $destination->id(), 'digest body', 'MarkdownV2' );
		$messages->mark_dead_letter( $message->id(), 'telegram_terminal_rejection' );

		$page = $this->make_page( $bots, $destinations );

		ob_start();
		$page->render_tab_content();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Dead-lettered messages', $html );
		$this->assertStringContainsString( 'Requeue retries the same stored message', $html );
		$this->assertStringContainsString( 'value="dismiss_dead_letter"', $html );
		$this->assertStringContainsString( 'telegram_terminal_rejection', $html );
	}
}
