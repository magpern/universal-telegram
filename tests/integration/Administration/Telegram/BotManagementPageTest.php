<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Administration\Telegram;

use UniversalTelegram\Administration\Telegram\BotManagementPage;
use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;
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

	public function test_the_rendered_page_never_exposes_the_plaintext_token_or_any_ciphertext(): void {
		$schema_health = new SchemaHealth();
		$vault         = new CredentialVault();

		$bots         = new BotProfileRepository( $schema_health, $vault );
		$destinations = new DestinationRepository( $schema_health );
		$updates      = new UpdateRepository( $schema_health );
		$messages     = new OutboundMessageRepository( $schema_health, $vault );

		$known_plaintext_token = '123456789:AAH_a-known-synthetic-token-value';
		$bot                   = $bots->create( 'My Bot', $known_plaintext_token );

		$page = new BotManagementPage( $bots, $destinations, $updates, $messages );

		ob_start();
		$page->render_tab_content();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'My Bot', $html );
		$this->assertStringNotContainsString( $known_plaintext_token, $html );
		$this->assertStringNotContainsString( $bot->token_ciphertext(), $html );
		$this->assertStringNotContainsString( $bot->webhook_secret_ciphertext(), $html );
	}

	public function test_an_unauthorized_user_is_denied(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$schema_health = new SchemaHealth();
		$vault         = new CredentialVault();

		$page = new BotManagementPage(
			new BotProfileRepository( $schema_health, $vault ),
			new DestinationRepository( $schema_health ),
			new UpdateRepository( $schema_health ),
			new OutboundMessageRepository( $schema_health, $vault )
		);

		$this->expectException( \WPDieException::class );
		$page->render_tab_content();
	}
}
