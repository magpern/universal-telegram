<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Core\Lifecycle;

use ActionScheduler;
use UniversalTelegram\Audit\AuditLogger;
use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;
use UniversalTelegram\Core\Configuration\Settings;
use UniversalTelegram\Core\Lifecycle\Deactivator;
use UniversalTelegram\Core\Lifecycle\Uninstaller;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Persistence\MigrationLock;
use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Privacy\Classification;
use UniversalTelegram\Privacy\Redactor;
use UniversalTelegram\Queue\Dispatcher;
use UniversalTelegram\Queue\JobEnvelope;
use UniversalTelegram\Queue\WorkerRunner;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;
use WP_Error;
use WP_UnitTestCase;

/**
 * Tested against both the WordPress-only and the WooCommerce-present
 * configuration (see bin/docker/test-integration-wc-present.sh), covering
 * all four combinations of the retention setting and WooCommerce's
 * presence together with the two configurations this file itself runs
 * under.
 */
final class UninstallTest extends WP_UnitTestCase {

	private const M01_TABLES = array(
		Migrator::BOTS_TABLE,
		Migrator::DESTINATIONS_TABLE,
		Migrator::OUTBOUND_MESSAGES_TABLE,
		Migrator::INBOUND_UPDATES_TABLE,
		Migrator::CIRCUIT_BREAKER_TABLE,
		Migrator::RATE_LIMIT_TABLE,
	);

	private const M02_TABLES = array(
		Migrator::EVENT_HISTORY_TABLE,
		Migrator::FATAL_ERROR_MARKERS_TABLE,
		Migrator::NOTIFICATION_RULES_TABLE,
		Migrator::DISPATCH_LOG_TABLE,
	);

	private const M05_TABLES = array(
		Migrator::CONVERSATIONS_TABLE,
		Migrator::CONVERSATION_MESSAGES_TABLE,
	);

	private const M07_TABLES = array(
		Migrator::OPERATOR_IDENTITIES_TABLE,
		Migrator::CONVERSATION_NOTES_TABLE,
		Migrator::OPERATOR_AVAILABILITY_TABLE,
	);

	private const M11A_TABLES = array(
		Migrator::VISITOR_DIGEST_COUNTERS_TABLE,
		Migrator::VISITOR_DIGEST_STATE_TABLE,
	);

	private const M11B_TABLES = array(
		Migrator::OPERATIONAL_SUMMARY_RUNS_TABLE,
		Migrator::INTELLIGENCE_SETTINGS_STATE_TABLE,
		Migrator::OPERATIONAL_ALERT_STATE_TABLE,
		Migrator::OPERATIONAL_SUMMARY_AI_DRAFTS_TABLE,
	);

	private const ADAPTER_M1_TABLES = array(
		Migrator::SUPPORT_CHAT_BINDINGS_TABLE,
		Migrator::SUPPORT_CHAT_DELIVERY_KEYS_TABLE,
	);

	private const QUIESCENCE_TABLES = array(
		Migrator::QUIESCENCE_STATE_TABLE,
		Migrator::QUIESCENCE_TRANSITIONS_TABLE,
		Migrator::QUIESCENCE_DEFERRED_UPDATES_TABLE,
	);

	protected function setUp(): void {
		parent::setUp();
		( new CapabilityRegistrar() )->grant_to_administrator();
	}

	private function table_exists( string $table ): bool {
		global $wpdb;

		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	public function test_default_retention_keeps_data_but_always_cleans_pending_actions_capability_and_deregisters_webhooks(): void {
		global $wpdb;

		delete_option( Settings::OPTION_NAME );

		$schema_health = new SchemaHealth();
		$dispatcher    = new Dispatcher( $schema_health );
		$result        = $dispatcher->enqueue( new JobEnvelope( 'test.uninstall_pending', array(), array() ) );
		$this->assertNotNull( $result->action_id() );

		$this->assertTrue( get_role( 'administrator' )->has_cap( CapabilityRegistrar::MANAGE ) );

		$bots = new BotProfileRepository( $schema_health, new CredentialVault() );
		$bots->create( 'Bot', 'fake-token' );

		$deregistration_calls = 0;
		$callback             = function () use ( &$deregistration_calls ) {
			++$deregistration_calls;
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
		add_filter( 'pre_http_request', $callback, 10, 0 );

		( new Deactivator() )->deactivate();
		( new Uninstaller() )->run();

		remove_filter( 'pre_http_request', $callback );

		$this->assertGreaterThan( 0, $deregistration_calls );

		// Unconditional, regardless of the retention setting.
		$this->assertFalse( get_role( 'administrator' )->has_cap( CapabilityRegistrar::MANAGE ) );
		$this->assertFalse( as_next_scheduled_action( WorkerRunner::HOOK, null, WorkerRunner::GROUP ) );

		// Retention-gated: the tables, settings, and schema version remain.
		$table = $wpdb->prefix . Migrator::AUDIT_LOG_TABLE;
		$this->assertTrue( $this->table_exists( $table ) );
		foreach ( array_merge( self::M01_TABLES, self::M02_TABLES, self::M05_TABLES, self::M07_TABLES ) as $table_name ) {
			$this->assertTrue( $this->table_exists( $wpdb->prefix . $table_name ), "Expected {$table_name} to still exist." );
		}
		$this->assertNotFalse( get_option( 'universal_telegram_db_version' ) );

		// Action Scheduler's own tables are never touched.
		$as_table = $wpdb->prefix . 'actionscheduler_actions';
		$this->assertTrue( $this->table_exists( $as_table ) );
	}

	public function test_webhook_deregistration_is_attempted_regardless_of_retention_setting_and_failures_are_swallowed(): void {
		$schema_health = new SchemaHealth();
		$bots          = new BotProfileRepository( $schema_health, new CredentialVault() );
		$bots->create( 'Bot', 'fake-token' );

		$callback = static function () {
			return new WP_Error( 'http_request_failed', 'Connection timed out' );
		};
		add_filter( 'pre_http_request', $callback, 10, 0 );

		( new Deactivator() )->deactivate();

		// A network failure while deregistering must never abort the rest
		// of uninstall (retention is left at its default, false, here).
		( new Uninstaller() )->run();

		remove_filter( 'pre_http_request', $callback );

		$this->assertFalse( get_role( 'administrator' )->has_cap( CapabilityRegistrar::MANAGE ) );
	}

	public function test_explicit_opt_in_removes_data_including_the_six_telegram_tables_and_historical_actions(): void {
		global $wpdb;

		update_option( Settings::OPTION_NAME, array( 'remove_data_on_uninstall' => true ) );

		$schema_health = new SchemaHealth();
		$audit_logger  = new AuditLogger( $schema_health, new Redactor() );
		$audit_logger->record( 'test.entry', 'system', null, array(), array(), Classification::PUBLIC );

		// A historical, already-complete action in the plugin's own group.
		$action_id = as_enqueue_async_action( WorkerRunner::HOOK, array(), WorkerRunner::GROUP );
		ActionScheduler::store()->mark_complete( $action_id );

		( new Deactivator() )->deactivate();

		// WP_UnitTestCase's own transaction isolation rewrites CREATE/DROP
		// TABLE into CREATE/DROP TEMPORARY TABLE (see its
		// _create_temporary_tables()/_drop_temporary_tables() query
		// filters). Every plugin table was created during this test run's
		// initial bootstrap, before that rewriting was active, so each is a
		// genuine permanent table — a later, rewritten "DROP TEMPORARY
		// TABLE" against it silently no-ops, since no session-local
		// temporary table by that name exists. This is a test-framework
		// artifact only: production uninstall.php runs in an ordinary
		// request with no such filter present. Removing it for this one
		// assertion reflects that real behaviour.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		try {
			( new Uninstaller() )->run();

			$table = $wpdb->prefix . Migrator::AUDIT_LOG_TABLE;
			$this->assertFalse( $this->table_exists( $table ) );

			foreach ( array_merge( self::M01_TABLES, self::M02_TABLES, self::M05_TABLES, self::M07_TABLES, self::M11A_TABLES, self::M11B_TABLES, self::ADAPTER_M1_TABLES, self::QUIESCENCE_TABLES ) as $table_name ) {
				$this->assertFalse( $this->table_exists( $wpdb->prefix . $table_name ), "Expected {$table_name} to have been dropped." );
			}

			$this->assertFalse( get_option( Settings::OPTION_NAME ) );
			$this->assertFalse( get_option( 'universal_telegram_db_version' ) );

			$remaining = ActionScheduler::store()->query_actions( array( 'group' => WorkerRunner::GROUP ), 'count' );
			$this->assertSame( 0, (int) $remaining );

			// Action Scheduler's own tables are never dropped, even though
			// the plugin's own historical rows within them are removed.
			$as_table = $wpdb->prefix . 'actionscheduler_actions';
			$this->assertTrue( $this->table_exists( $as_table ) );
		} finally {
			delete_option( 'universal_telegram_migration_lock' );
			$this->recreate_all_tables();

			self::commit_transaction();
			$this->start_transaction();
		}
	}

	/**
	 * Recreates every plugin table by running the real Migrator, at every
	 * db_version it knows about, rather than hand-duplicating its DDL (the
	 * previous approach here silently went stale at db_version 12 across
	 * every schema addition from M06 onward, since nothing kept a second,
	 * hand-copied DDL surface in sync with Migrator's own). Safe to call
	 * here specifically because the `_create_temporary_tables()`/
	 * `_drop_temporary_tables()` query filters are still removed at this
	 * point in the test (removed above, restored only after this method
	 * returns, by `self::commit_transaction(); $this->start_transaction();`
	 * below) — so every CREATE TABLE this produces is a genuine permanent
	 * table, exactly like the real uninstalled tables were, and every
	 * later test in the suite sees an accurate, current schema and
	 * db_version again.
	 */
	private function recreate_all_tables(): void {
		delete_option( 'universal_telegram_db_version' );
		( new Migrator( new MigrationLock() ) )->maybe_migrate();
	}
}
