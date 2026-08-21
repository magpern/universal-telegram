<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Telegram\Configuration;

use UniversalTelegram\Audit\AuditLogger;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Privacy\Redactor;
use UniversalTelegram\Telegram\Client\TelegramApiClient;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;
use UniversalTelegram\Telegram\Configuration\RegistrationOutcome;
use UniversalTelegram\Telegram\Configuration\WebhookRegistrationCoordinator;
use UniversalTelegram\Telegram\Inbound\WebhookSecretVerifier;
use WP_Error;
use WP_UnitTestCase;

final class WebhookRegistrationCoordinatorTest extends WP_UnitTestCase {

	/**
	 * @var array<int, callable>
	 */
	private array $filters_to_remove = array();

	protected function tearDown(): void {
		foreach ( $this->filters_to_remove as $callback ) {
			remove_filter( 'pre_http_request', $callback );
		}
		$this->filters_to_remove = array();

		parent::tearDown();
	}

	private function fake_response( int $status, array $body ): void {
		$callback = static function () use ( $status, $body ) {
			return array(
				'response' => array( 'code' => $status ),
				'body'     => wp_json_encode( $body ),
			);
		};
		add_filter( 'pre_http_request', $callback, 10, 0 );
		$this->filters_to_remove[] = $callback;
	}

	private function fake_network_error(): void {
		$callback = static function () {
			return new WP_Error( 'http_request_failed', 'Connection timed out' );
		};
		add_filter( 'pre_http_request', $callback, 10, 0 );
		$this->filters_to_remove[] = $callback;
	}

	private function coordinator( BotProfileRepository $bots ): WebhookRegistrationCoordinator {
		return new WebhookRegistrationCoordinator(
			$bots,
			new TelegramApiClient(),
			new AuditLogger( new SchemaHealth(), new Redactor() ),
			static function (): string {
				return 'https://example.com/wp-json/universal-telegram/v1/webhook/';
			}
		);
	}

	/**
	 * Regression for the bootstrap fatal: rest_url() (or any URL provider)
	 * must never be evaluated at construction time, since this class is
	 * constructed during Core\Plugin::init() on plugins_loaded, before
	 * WordPress' rewrite state exists. The provider must be called only
	 * when an operation is actually attempted.
	 */
	public function test_the_webhook_base_url_provider_is_never_called_at_construction_time(): void {
		$schema_health = new SchemaHealth();
		$bots          = new BotProfileRepository( $schema_health, new CredentialVault() );

		$calls = 0;

		new WebhookRegistrationCoordinator(
			$bots,
			new TelegramApiClient(),
			new AuditLogger( $schema_health, new Redactor() ),
			static function () use ( &$calls ): string {
				$calls++;
				return 'https://example.com/wp-json/universal-telegram/v1/webhook/';
			}
		);

		$this->assertSame( 0, $calls, 'The URL provider must not be called at construction time.' );
	}

	/**
	 * Scenario 1: clean initial registration confirms immediately using
	 * the bot's one existing active secret, no pending secret ever created.
	 */
	public function test_clean_initial_registration_confirms_immediately(): void {
		$schema_health = new SchemaHealth();
		$bots          = new BotProfileRepository( $schema_health, new CredentialVault() );
		$bot           = $bots->create( 'Bot', 'token' );

		$this->fake_response(
			200,
			array(
				'ok'     => true,
				'result' => true,
			)
		);

		$outcome = $this->coordinator( $bots )->register( $bot );

		$this->assertSame( RegistrationOutcome::SUCCESS, $outcome );

		$after = $bots->find( $bot->id() );
		$this->assertSame( 'registered', $after->webhook_registration_state() );
		$this->assertFalse( $after->has_pending_secret() );
		$this->assertNotNull( $after->webhook_registered_at() );
	}

	/**
	 * Scenario 2a: an uncertain rotation leaves both active and pending
	 * valid indefinitely, with no automatic change once the stale
	 * threshold elapses — only a diagnostic alert condition.
	 */
	public function test_an_uncertain_rotation_leaves_both_secrets_valid_indefinitely(): void {
		$schema_health = new SchemaHealth();
		$bots          = new BotProfileRepository( $schema_health, new CredentialVault() );
		$bot           = $bots->create( 'Bot', 'token' );

		$this->fake_network_error();
		$outcome = $this->coordinator( $bots )->rotate( $bot );

		$this->assertSame( RegistrationOutcome::UNCERTAIN, $outcome );

		$after = $bots->find( $bot->id() );
		$this->assertTrue( $after->has_pending_secret() );
		$this->assertSame( 'uncertain', $after->webhook_registration_state() );

		$verifier      = new WebhookSecretVerifier( $bots, new AuditLogger( $schema_health, new Redactor() ) );
		$active_secret = $bots->decrypt_webhook_secret( $after )->plaintext();
		$this->assertTrue( $verifier->verify( $bots->find( $bot->id() ), $active_secret ) );

		// Age the pending secret arbitrarily. Nothing changes merely from
		// the passage of time: it remains set, and the bot now qualifies
		// as a stale unresolved rotation for the diagnostic alert.
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'universal_telegram_bots',
			array( 'webhook_secret_pending_since' => gmdate( 'Y-m-d H:i:s', time() - ( 365 * DAY_IN_SECONDS ) ) ),
			array( 'id' => $bot->id() )
		);

		$stale_count = $bots->count_stale_unresolved_registrations( 24 );
		$this->assertSame( 1, $stale_count );

		$still_pending = $bots->find( $bot->id() );
		$this->assertTrue( $still_pending->has_pending_secret() );

		// The pending secret, despite its age, still authenticates — and
		// doing so is itself the traffic-based confirmation path that
		// resolves it (promotes it to active), exactly as ADR-0013 intends.
		$pending_secret = $bots->decrypt_pending_webhook_secret( $still_pending )->plaintext();
		$this->assertTrue( $verifier->verify( $bots->find( $bot->id() ), $pending_secret ) );

		$resolved = $bots->find( $bot->id() );
		$this->assertFalse( $resolved->has_pending_secret() );
		$this->assertSame( 'registered', $resolved->webhook_registration_state() );
	}

	/**
	 * Scenario 2b: an uncertain initial registration's staleness is
	 * measured from webhook_last_attempt_at, not created_at/updated_at.
	 */
	public function test_an_uncertain_initial_registration_staleness_uses_last_attempt_at(): void {
		$schema_health = new SchemaHealth();
		$bots          = new BotProfileRepository( $schema_health, new CredentialVault() );
		$bot           = $bots->create( 'Bot', 'token' );

		$this->fake_network_error();
		$outcome = $this->coordinator( $bots )->register( $bot );

		$this->assertSame( RegistrationOutcome::UNCERTAIN, $outcome );

		$after = $bots->find( $bot->id() );
		$this->assertFalse( $after->has_pending_secret() );
		$this->assertSame( 'uncertain', $after->webhook_registration_state() );
		$this->assertNotNull( $after->webhook_last_attempt_at() );

		$this->assertSame( 0, $bots->count_stale_unresolved_registrations( 24 ) );

		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'universal_telegram_bots',
			array(
				'webhook_last_attempt_at' => gmdate( 'Y-m-d H:i:s', time() - ( 48 * HOUR_IN_SECONDS ) ),
				'created_at'              => gmdate( 'Y-m-d H:i:s', time() ),
				'updated_at'              => gmdate( 'Y-m-d H:i:s', time() ),
			),
			array( 'id' => $bot->id() )
		);

		$this->assertSame( 1, $bots->count_stale_unresolved_registrations( 24 ) );
	}

	/**
	 * Scenario 3: retry_pending resends the byte-identical secret rotate()
	 * originally generated, never a freshly generated one.
	 */
	public function test_retry_pending_resends_the_identical_pending_secret(): void {
		$schema_health = new SchemaHealth();
		$bots          = new BotProfileRepository( $schema_health, new CredentialVault() );
		$bot           = $bots->create( 'Bot', 'token' );

		$this->fake_network_error();
		$this->coordinator( $bots )->rotate( $bot );

		$reloaded         = $bots->find( $bot->id() );
		$original_pending = $bots->decrypt_pending_webhook_secret( $reloaded )->plaintext();

		$sent_secret = null;
		$callback    = function () use ( &$sent_secret ) {
			$args        = func_get_args();
			$sent_secret = $args[1]['body']['secret_token'] ?? null;
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode(
					array(
						'ok'     => true,
						'result' => true,
					)
				),
			);
		};
		add_filter( 'pre_http_request', $callback, 10, 3 );
		$this->filters_to_remove[] = $callback;

		$outcome = $this->coordinator( $bots )->retry_pending( $bots->find( $bot->id() ) );

		$this->assertSame( RegistrationOutcome::SUCCESS, $outcome );
		$this->assertSame( $original_pending, $sent_secret );
	}

	/**
	 * Scenario 4 and 5: an explicit rollback only clears pending after a
	 * confirmed Telegram success; a failed or uncertain rollback leaves
	 * both secrets intact and both still authenticate.
	 */
	public function test_rollback_only_clears_pending_on_confirmed_success_and_preserves_both_secrets_otherwise(): void {
		$schema_health = new SchemaHealth();
		$bots          = new BotProfileRepository( $schema_health, new CredentialVault() );
		$verifier      = new WebhookSecretVerifier( $bots, new AuditLogger( $schema_health, new Redactor() ) );

		// Failed rollback preserves both secrets.
		$bot_failed = $bots->create( 'Bot Failed', 'token' );
		$bots->start_pending_secret( $bot_failed->id(), 'pending-secret-failed' );
		$bots->mark_uncertain( $bot_failed->id() );

		$this->fake_response(
			400,
			array(
				'ok'          => false,
				'error_code'  => 400,
				'description' => 'Bad Request',
			)
		);
		$outcome = $this->coordinator( $bots )->rollback( $bots->find( $bot_failed->id() ) );

		$this->assertSame( RegistrationOutcome::REJECTED, $outcome );
		$after_failed = $bots->find( $bot_failed->id() );
		$this->assertTrue( $after_failed->has_pending_secret() );

		$active_secret  = $bots->decrypt_webhook_secret( $after_failed )->plaintext();
		$pending_secret = $bots->decrypt_pending_webhook_secret( $after_failed )->plaintext();
		$this->assertTrue( $verifier->verify( $bots->find( $bot_failed->id() ), $active_secret ) );
		$this->assertTrue( $verifier->verify( $bots->find( $bot_failed->id() ), $pending_secret ) );

		// Uncertain rollback also preserves both secrets.
		$bot_uncertain = $bots->create( 'Bot Uncertain', 'token' );
		$bots->start_pending_secret( $bot_uncertain->id(), 'pending-secret-uncertain' );
		$bots->mark_uncertain( $bot_uncertain->id() );

		$this->fake_network_error();
		$outcome_uncertain = $this->coordinator( $bots )->rollback( $bots->find( $bot_uncertain->id() ) );

		$this->assertSame( RegistrationOutcome::UNCERTAIN, $outcome_uncertain );
		$this->assertTrue( $bots->find( $bot_uncertain->id() )->has_pending_secret() );

		// Confirmed rollback clears pending and restores 'registered'.
		$bot_success = $bots->create( 'Bot Success', 'token' );
		$bots->start_pending_secret( $bot_success->id(), 'pending-secret-success' );
		$bots->mark_uncertain( $bot_success->id() );

		$this->fake_response(
			200,
			array(
				'ok'     => true,
				'result' => true,
			)
		);
		$outcome_success = $this->coordinator( $bots )->rollback( $bots->find( $bot_success->id() ) );

		$this->assertSame( RegistrationOutcome::SUCCESS, $outcome_success );
		$after_success = $bots->find( $bot_success->id() );
		$this->assertFalse( $after_success->has_pending_secret() );
		$this->assertSame( 'registered', $after_success->webhook_registration_state() );
	}

	/**
	 * Scenario 6: RetentionCleanupHandler's recurring pass never calls any
	 * BotProfileRepository write method on the pending-secret fields.
	 */
	public function test_retention_cleanup_never_touches_pending_secret_fields(): void {
		$schema_health = new SchemaHealth();
		$vault         = new CredentialVault();
		$bots          = new BotProfileRepository( $schema_health, $vault );
		$messages      = new \UniversalTelegram\Telegram\Outbound\OutboundMessageRepository( $schema_health, $vault );

		$bot = $bots->create( 'Bot', 'token' );
		$bots->start_pending_secret( $bot->id(), 'a-pending-secret' );
		$bots->mark_uncertain( $bot->id() );

		$before = $bots->find( $bot->id() );

		( new \UniversalTelegram\Telegram\Outbound\RetentionCleanupHandler( $messages, 30, 90 ) )->run();

		$after = $bots->find( $bot->id() );
		$this->assertSame( $before->webhook_secret_pending_ciphertext(), $after->webhook_secret_pending_ciphertext() );
		$this->assertSame( $before->webhook_secret_pending_since(), $after->webhook_secret_pending_since() );
		$this->assertSame( $before->webhook_registration_state(), $after->webhook_registration_state() );
	}
}
