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
		$forms = new TelegramFormFields();

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

	public function test_the_rendered_page_includes_the_setup_wizard(): void {
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

		// The wizard must render above the existing "Add a bot" form, not replace it.
		$this->assertStringContainsString( 'Add a bot', $html );
		$this->assertStringContainsString( 'name="name"', $html );
		$this->assertStringContainsString( 'name="token"', $html );

		$wizard_position = strpos( $html, 'Set up your Telegram bot' );
		$form_position    = strpos( $html, 'Add a bot' );
		$this->assertLessThan( $form_position, $wizard_position );
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
