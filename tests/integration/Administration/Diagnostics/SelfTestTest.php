<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Administration\Diagnostics;

use Throwable;
use UniversalTelegram\Administration\Diagnostics\DiagnosticsPage;
use UniversalTelegram\Administration\Diagnostics\DiagnosticsReport;
use UniversalTelegram\Administration\Diagnostics\SelfTest;
use UniversalTelegram\Audit\AuditLogger;
use UniversalTelegram\Audit\AuditLogRepository;
use UniversalTelegram\Automations\DispatchLogRepository;
use UniversalTelegram\Automations\NotificationRuleRepository;
use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;
use UniversalTelegram\Events\EventHistoryRepository;
use UniversalTelegram\Events\Registry;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Integrations\WooCommerce\WooCommerceSupport;
use UniversalTelegram\Persistence\MigrationFailureCode;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Privacy\Redactor;
use UniversalTelegram\Queue\Dispatcher;
use UniversalTelegram\Queue\HandlerRegistry;
use UniversalTelegram\Queue\QueueHealth;
use UniversalTelegram\Queue\RetryPolicy;
use UniversalTelegram\Queue\WorkerRunner;
use WP_UnitTestCase;

final class SelfTestTest extends WP_UnitTestCase {

	private const SECRET = 'UT-SELFTEST-4f8b2c91-4d3a-4b7e-9c1a-do-not-use';

	protected function setUp(): void {
		parent::setUp();

		// The test bootstrap loads the plugin as an MU-plugin, bypassing
		// WordPress' real activation flow, so the capability Activator
		// would normally grant is never actually granted here.
		( new CapabilityRegistrar() )->grant_to_administrator();
	}

	protected function tearDown(): void {
		unset( $_POST['fail_count'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );
		parent::tearDown();
	}

	private function make_self_test( SchemaHealth $schema_health ): SelfTest {
		return new SelfTest(
			$schema_health,
			new Dispatcher( $schema_health ),
			new CredentialVault(),
			new AuditLogger( $schema_health, new Redactor() )
		);
	}

	public function test_control_is_entirely_absent_when_debug_mode_is_off(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$self_test = new class( new SchemaHealth(), new Dispatcher( new SchemaHealth() ), new CredentialVault(), new AuditLogger( new SchemaHealth(), new Redactor() ) ) extends SelfTest {
			protected function is_debug_mode(): bool {
				return false;
			}
		};

		$this->assertFalse( $self_test->should_render() );

		ob_start();
		$self_test->render_control();
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_control_is_entirely_absent_when_the_current_user_lacks_the_capability(): void {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$this->assertFalse( $this->make_self_test( new SchemaHealth() )->should_render() );
	}

	public function test_control_is_entirely_absent_while_the_schema_is_degraded(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$schema_health = new SchemaHealth();
		$schema_health->mark_unavailable( MigrationFailureCode::STEP_FAILED );

		$this->assertFalse( $this->make_self_test( $schema_health )->should_render() );
	}

	public function test_a_request_without_a_valid_nonce_is_rejected(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$_POST['fail_count'] = '1';

		$this->expectException( \WPDieException::class );
		$this->make_self_test( new SchemaHealth() )->handle_request();
	}

	/**
	 * @dataProvider retry_contract_provider
	 */
	public function test_retry_contract( int $fail_count, int $expected_failures, bool $expect_success ): void {
		$schema_health    = new SchemaHealth();
		$handler_registry = new HandlerRegistry();
		$self_test        = $this->make_self_test( $schema_health );
		$handler_registry->register( SelfTest::JOB_TYPE, array( $self_test, 'handle_job' ) );

		$audit_logger = new AuditLogger( $schema_health, new Redactor() );
		$repository   = new AuditLogRepository( $schema_health );
		$retry_policy = new RetryPolicy(
			static function (): int {
				return time();
			},
			static function ( int $max ): int {
				return 0;
			}
		);
		$runner       = new WorkerRunner( $schema_health, $handler_registry, $retry_policy, $audit_logger );

		$succeeded = false;
		$failures  = 0;

		for ( $attempt = 1; $attempt <= 5; $attempt++ ) {
			$job = array(
				'job_id'   => 'self-test-' . $fail_count,
				'job_type' => SelfTest::JOB_TYPE,
				'attempt'  => $attempt,
				'payload'  => array( 'fail_count' => $fail_count ),
			);

			try {
				$runner->run( $job );
				$succeeded = true;
				break;
			} catch ( Throwable $exception ) {
				++$failures;
			}
		}

		$this->assertSame( $expect_success, $succeeded );
		$this->assertSame( $expected_failures, $failures );

		$entries        = $repository->recent( 20 );
		$attempt_failed = array_filter(
			$entries,
			static function ( array $entry ): bool {
				return 'queue_job_attempt_failed' === $entry['action'];
			}
		);
		$terminal       = array_filter(
			$entries,
			static function ( array $entry ): bool {
				return 'queue_job_terminal_failure' === $entry['action'];
			}
		);

		$this->assertCount( $expected_failures, $attempt_failed );
		$this->assertCount( $expect_success ? 0 : 1, $terminal );
	}

	/**
	 * @return array<string, array{0: int, 1: int, 2: bool}>
	 */
	public function retry_contract_provider(): array {
		return array(
			'fails once then succeeds'          => array( 1, 1, true ),
			'fails four times then succeeds'    => array( 4, 4, true ),
			'fails all five permitted attempts' => array( 5, 5, false ),
		);
	}

	public function test_the_synthetic_secret_never_appears_in_plaintext_anywhere(): void {
		global $wpdb;

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$schema_health    = new SchemaHealth();
		$dispatcher       = new Dispatcher( $schema_health );
		$credential_vault = new CredentialVault();
		$audit_logger     = new AuditLogger( $schema_health, new Redactor() );

		$self_test = new class( $schema_health, $dispatcher, $credential_vault, $audit_logger ) extends SelfTest {
			/**
			 * @var string|null
			 */
			public ?string $redirected_to = null;

			protected function redirect_and_exit( string $url ): void {
				$this->redirected_to = $url;
			}
		};

		$nonce = wp_create_nonce( 'universal_telegram_diag_self_test' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- constructing a test fixture request, not processing real external input.
		$_POST['fail_count']  = '1';
		$_POST['_wpnonce']    = $nonce;
		$_REQUEST['_wpnonce'] = $nonce;
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput

		$self_test->handle_request();

		$this->assertNotNull( $self_test->redirected_to );

		// 1. Not in the audit table's persisted context, checked against
		// every row, not only the most recent.
		$audit_table = $wpdb->prefix . 'universal_telegram_audit_log';
		$found       = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$audit_table} WHERE context LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name, never user input.
				'%' . $wpdb->esc_like( self::SECRET ) . '%'
			)
		);
		$this->assertSame( '0', (string) $found );

		// 2. Not in any Action Scheduler action's own stored arguments.
		$actions_table = $wpdb->prefix . 'actionscheduler_actions';
		$found_in_args = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$actions_table} WHERE args LIKE %s OR extended_args LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name, never user input.
				'%' . $wpdb->esc_like( self::SECRET ) . '%',
				'%' . $wpdb->esc_like( self::SECRET ) . '%'
			)
		);
		$this->assertSame( '0', (string) $found_in_args );

		// 3. Not in the diagnostics page's own rendered output.
		$vault            = new CredentialVault();
		$bots             = new \UniversalTelegram\Telegram\Configuration\BotProfileRepository( $schema_health, $vault );
		$destinations     = new \UniversalTelegram\Telegram\Configuration\DestinationRepository( $schema_health );
		$messages         = new \UniversalTelegram\Telegram\Outbound\OutboundMessageRepository( $schema_health, $vault );
		$breaker          = new \UniversalTelegram\Telegram\Reliability\CircuitBreaker( $schema_health, new RetryPolicy() );
		$alert            = new \UniversalTelegram\Telegram\Reliability\QueueHealthAlert( $messages, $breaker, $bots );
		$registry         = new Registry();
		$event_history    = new EventHistoryRepository( $schema_health, $registry, new Redactor() );
		$notification_rules = new NotificationRuleRepository( $schema_health, $registry );
		$dispatch_log     = new DispatchLogRepository( $schema_health );
		$report           = new DiagnosticsReport( new QueueHealth(), new AuditLogRepository( $schema_health ), new WooCommerceSupport(), $schema_health, $bots, $destinations, $alert, $event_history, $notification_rules, $dispatch_log );
		$diagnostics_page = new DiagnosticsPage( $report, $schema_health, $self_test, $alert );

		ob_start();
		$diagnostics_page->render();
		$output = ob_get_clean();

		$this->assertStringNotContainsString( self::SECRET, $output );
	}
}
