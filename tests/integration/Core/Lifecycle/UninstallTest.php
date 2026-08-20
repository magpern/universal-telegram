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
use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Privacy\Classification;
use UniversalTelegram\Privacy\Redactor;
use UniversalTelegram\Queue\Dispatcher;
use UniversalTelegram\Queue\JobEnvelope;
use UniversalTelegram\Queue\WorkerRunner;
use WP_UnitTestCase;

/**
 * Tested against both the WordPress-only and the WooCommerce-present
 * configuration (see bin/docker/test-integration-wc-present.sh), covering
 * all four combinations of the retention setting and WooCommerce's
 * presence together with the two configurations this file itself runs
 * under.
 */
final class UninstallTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		( new CapabilityRegistrar() )->grant_to_administrator();
	}

	public function test_default_retention_keeps_data_but_always_cleans_pending_actions_and_capability(): void {
		global $wpdb;

		delete_option( Settings::OPTION_NAME );

		$schema_health = new SchemaHealth();
		$dispatcher    = new Dispatcher( $schema_health );
		$result        = $dispatcher->enqueue( new JobEnvelope( 'test.uninstall_pending', array(), array() ) );
		$this->assertNotNull( $result->action_id() );

		$this->assertTrue( get_role( 'administrator' )->has_cap( CapabilityRegistrar::MANAGE ) );

		( new Deactivator() )->deactivate();
		( new Uninstaller() )->run();

		// Unconditional, regardless of the retention setting.
		$this->assertFalse( get_role( 'administrator' )->has_cap( CapabilityRegistrar::MANAGE ) );
		$this->assertFalse( as_next_scheduled_action( WorkerRunner::HOOK, null, WorkerRunner::GROUP ) );

		// Retention-gated: the table, settings, and schema version remain.
		$table = $wpdb->prefix . Migrator::AUDIT_LOG_TABLE;
		$this->assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) );
		$this->assertNotFalse( get_option( 'universal_telegram_db_version' ) );

		// Action Scheduler's own tables are never touched.
		$as_table = $wpdb->prefix . 'actionscheduler_actions';
		$this->assertSame( $as_table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $as_table ) ) );
	}

	public function test_explicit_opt_in_removes_data_and_historical_actions(): void {
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
		// filters). The plugin's real audit-log table was created during
		// this test run's initial bootstrap, before that rewriting was
		// active, so it is a genuine permanent table — a later, rewritten
		// "DROP TEMPORARY TABLE" against it silently no-ops, since no
		// session-local temporary table by that name exists. This is a
		// test-framework artifact only: production uninstall.php runs in
		// an ordinary request with no such filter present. Removing it for
		// this one assertion reflects that real behaviour.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		try {
			( new Uninstaller() )->run();

			$table = $wpdb->prefix . Migrator::AUDIT_LOG_TABLE;
			$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) );

			$this->assertFalse( get_option( Settings::OPTION_NAME ) );
			$this->assertFalse( get_option( 'universal_telegram_db_version' ) );

			$remaining = ActionScheduler::store()->query_actions( array( 'group' => WorkerRunner::GROUP ), 'count' );
			$this->assertSame( 0, (int) $remaining );

			// Action Scheduler's own tables are never dropped, even though
			// the plugin's own historical rows within them are removed.
			$as_table = $wpdb->prefix . 'actionscheduler_actions';
			$this->assertSame( $as_table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $as_table ) ) );
		} finally {
			// The real CREATE TABLE above already caused an implicit
			// commit of this test's own transaction (any DDL does), so
			// Persistence\MigrationLock's own acquire/release around a
			// full maybe_migrate() call here would straddle that boundary
			// unpredictably. Recreate the table directly instead, then
			// explicitly close out this test's transaction and open a
			// fresh one — start_transaction() itself re-adds both query
			// filters this test removed above.
			delete_option( 'universal_telegram_migration_lock' );
			$charset_collate = $wpdb->get_charset_collate();
			$table           = $wpdb->prefix . Migrator::AUDIT_LOG_TABLE;
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- fixed table name, never user input.
			$wpdb->query(
				"CREATE TABLE IF NOT EXISTS {$table} (
					id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
					occurred_at DATETIME NOT NULL,
					actor_type VARCHAR(32) NOT NULL,
					actor_id BIGINT UNSIGNED NULL,
					action VARCHAR(191) NOT NULL,
					context LONGTEXT NULL,
					privacy_classification VARCHAR(16) NOT NULL,
					PRIMARY KEY (id),
					KEY occurred_at (occurred_at),
					KEY action (action)
				) {$charset_collate}"
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			update_option( 'universal_telegram_db_version', 1 );

			self::commit_transaction();
			$this->start_transaction();
		}
	}
}
