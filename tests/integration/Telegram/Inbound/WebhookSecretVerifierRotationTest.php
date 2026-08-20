<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Telegram\Inbound;

use UniversalTelegram\Audit\AuditLogger;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Privacy\Redactor;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;
use UniversalTelegram\Telegram\Inbound\WebhookSecretVerifier;
use WP_UnitTestCase;

final class WebhookSecretVerifierRotationTest extends WP_UnitTestCase {

	/**
	 * @var BotProfileRepository
	 */
	private BotProfileRepository $bots;

	/**
	 * @var WebhookSecretVerifier
	 */
	private WebhookSecretVerifier $verifier;

	protected function setUp(): void {
		parent::setUp();

		$schema_health  = new SchemaHealth();
		$this->bots     = new BotProfileRepository( $schema_health, new CredentialVault() );
		$this->verifier = new WebhookSecretVerifier( $this->bots, new AuditLogger( $schema_health, new Redactor() ) );
	}

	public function test_active_secret_authenticates(): void {
		$bot    = $this->bots->create( 'Bot', 'token' );
		$secret = $this->bots->decrypt_webhook_secret( $bot )->plaintext();

		$this->assertTrue( $this->verifier->verify( $this->bots->find( $bot->id() ), $secret ) );
	}

	public function test_a_pending_secret_authenticates_indefinitely_and_promotes_it_on_match(): void {
		$bot = $this->bots->create( 'Bot', 'token' );
		$this->bots->start_pending_secret( $bot->id(), 'newly-generated-pending-secret' );

		// Simulate the pending secret being arbitrarily old — no time limit
		// applies to its acceptance.
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'universal_telegram_bots',
			array( 'webhook_secret_pending_since' => gmdate( 'Y-m-d H:i:s', time() - ( 365 * DAY_IN_SECONDS ) ) ),
			array( 'id' => $bot->id() )
		);

		$loaded = $this->bots->find( $bot->id() );

		$this->assertTrue( $this->verifier->verify( $loaded, 'newly-generated-pending-secret' ) );

		$after = $this->bots->find( $bot->id() );
		$this->assertFalse( $after->has_pending_secret() );
		$this->assertSame( 'registered', $after->webhook_registration_state() );

		$active_now = $this->bots->decrypt_webhook_secret( $after )->plaintext();
		$this->assertSame( 'newly-generated-pending-secret', $active_now );
	}

	public function test_an_uncertain_initial_registration_is_confirmed_via_traffic(): void {
		$bot = $this->bots->create( 'Bot', 'token' );
		$this->bots->mark_uncertain( $bot->id() );
		$this->bots->touch_last_attempt( $bot->id() );

		$loaded = $this->bots->find( $bot->id() );
		$this->assertFalse( $loaded->has_pending_secret() );

		$secret = $this->bots->decrypt_webhook_secret( $loaded )->plaintext();

		$this->assertTrue( $this->verifier->verify( $loaded, $secret ) );

		$after = $this->bots->find( $bot->id() );
		$this->assertSame( 'registered', $after->webhook_registration_state() );
	}

	public function test_an_active_match_while_pending_still_exists_changes_nothing(): void {
		$bot = $this->bots->create( 'Bot', 'token' );
		$this->bots->start_pending_secret( $bot->id(), 'pending-secret' );
		$this->bots->mark_uncertain( $bot->id() );

		$loaded        = $this->bots->find( $bot->id() );
		$active_secret = $this->bots->decrypt_webhook_secret( $loaded )->plaintext();

		$this->assertTrue( $this->verifier->verify( $loaded, $active_secret ) );

		$after = $this->bots->find( $bot->id() );
		$this->assertTrue( $after->has_pending_secret() );
		$this->assertSame( 'uncertain', $after->webhook_registration_state() );
	}

	public function test_a_wrong_secret_is_rejected(): void {
		$bot = $this->bots->create( 'Bot', 'token' );

		$this->assertFalse( $this->verifier->verify( $this->bots->find( $bot->id() ), 'totally-wrong' ) );
	}

	public function test_a_null_header_is_rejected(): void {
		$bot = $this->bots->create( 'Bot', 'token' );

		$this->assertFalse( $this->verifier->verify( $this->bots->find( $bot->id() ), null ) );
	}
}
