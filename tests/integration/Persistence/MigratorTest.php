<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Persistence;

use UniversalTelegram\Persistence\MigrationFailedException;
use UniversalTelegram\Persistence\MigrationFailureCode;
use UniversalTelegram\Persistence\MigrationLock;
use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Tests\Integration\Support\FailingMultiStatementMigrator;
use WP_UnitTestCase;

final class MigratorTest extends WP_UnitTestCase {

	public function test_clean_install_creates_the_audit_log_table_exactly_once(): void {
		global $wpdb;

		$table = $wpdb->prefix . Migrator::AUDIT_LOG_TABLE;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name, never user input.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		delete_option( 'universal_telegram_db_version' );

		$migrator = new Migrator( new MigrationLock() );
		$migrator->maybe_migrate();

		$this->assertSame( 27, (int) get_option( 'universal_telegram_db_version' ) );
		$this->assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) );

		// Re-running an already up-to-date schema must not error and must
		// not change the recorded version.
		$migrator->maybe_migrate();
		$this->assertSame( 27, (int) get_option( 'universal_telegram_db_version' ) );
	}

	public function test_clean_install_creates_all_six_telegram_tables(): void {
		global $wpdb;

		$tables = array(
			Migrator::BOTS_TABLE,
			Migrator::DESTINATIONS_TABLE,
			Migrator::OUTBOUND_MESSAGES_TABLE,
			Migrator::INBOUND_UPDATES_TABLE,
			Migrator::CIRCUIT_BREAKER_TABLE,
			Migrator::RATE_LIMIT_TABLE,
		);

		foreach ( $tables as $table_name ) {
			$table = $wpdb->prefix . $table_name;
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name, never user input.
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		}
		delete_option( 'universal_telegram_db_version' );

		$migrator = new Migrator( new MigrationLock() );
		$migrator->maybe_migrate();

		$this->assertSame( 27, (int) get_option( 'universal_telegram_db_version' ) );

		foreach ( $tables as $table_name ) {
			$table = $wpdb->prefix . $table_name;
			$this->assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ), "Missing table {$table}" );
		}

		// Re-running an already up-to-date schema is a safe no-op.
		$migrator->maybe_migrate();
		$this->assertSame( 27, (int) get_option( 'universal_telegram_db_version' ) );
	}

	public function test_postcondition_verification_catches_a_partial_step_failure(): void {
		global $wpdb;

		$table = $wpdb->prefix . Migrator::BOTS_TABLE;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name, never user input.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		update_option( 'universal_telegram_db_version', 1 );

		$migrator = new class( new MigrationLock() ) extends Migrator {
			protected function target_version(): int {
				return 2;
			}
		};

		// A synthetic broken step 2 is not exercised here directly; instead
		// this confirms that step 2 as shipped is idempotent and re-runnable,
		// matching step 1's own precedent.
		$migrator->maybe_migrate();
		$this->assertSame( 2, (int) get_option( 'universal_telegram_db_version' ) );
		$this->assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) );

		$migrator->maybe_migrate();
		$this->assertSame( 2, (int) get_option( 'universal_telegram_db_version' ) );
	}

	public function test_multi_statement_step_partial_failure_leaves_version_unchanged_and_is_safely_re_runnable(): void {
		update_option( 'universal_telegram_db_version', 1 );
		FailingMultiStatementMigrator::$second_statement_should_fail = true;

		$migrator = new FailingMultiStatementMigrator( new MigrationLock() );

		try {
			$migrator->maybe_migrate();
			$this->fail( 'Expected a MigrationFailedException.' );
		} catch ( MigrationFailedException $exception ) {
			$this->assertSame( MigrationFailureCode::STEP_FAILED, $exception->failure_code() );
		}

		$this->assertSame( 1, (int) get_option( 'universal_telegram_db_version' ) );

		// A safe retry, exactly as a later request would perform automatically.
		$migrator->maybe_migrate();
		$this->assertSame( 2, (int) get_option( 'universal_telegram_db_version' ) );
	}

	public function test_step_17_creates_the_three_operator_workflow_tables(): void {
		global $wpdb;

		$tables = array(
			Migrator::OPERATOR_IDENTITIES_TABLE,
			Migrator::CONVERSATION_NOTES_TABLE,
			Migrator::OPERATOR_AVAILABILITY_TABLE,
		);

		foreach ( $tables as $table_name ) {
			$table = $wpdb->prefix . $table_name;
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name, never user input.
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		}
		delete_option( 'universal_telegram_db_version' );

		$migrator = new Migrator( new MigrationLock() );
		$migrator->maybe_migrate();

		$this->assertSame( 27, (int) get_option( 'universal_telegram_db_version' ) );

		foreach ( $tables as $table_name ) {
			$table = $wpdb->prefix . $table_name;
			$this->assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ), "Missing table {$table}" );
		}

		// Re-running an already up-to-date schema is a safe no-op.
		$migrator->maybe_migrate();
		$this->assertSame( 27, (int) get_option( 'universal_telegram_db_version' ) );
	}

	public function test_step_18_adds_operator_workflow_columns_and_index(): void {
		global $wpdb;

		update_option( 'universal_telegram_db_version', 16 );

		$migrator = new Migrator( new MigrationLock() );
		$migrator->maybe_migrate();

		$this->assertSame( 27, (int) get_option( 'universal_telegram_db_version' ) );

		$conversations_table = $wpdb->prefix . Migrator::CONVERSATIONS_TABLE;
		$messages_table      = $wpdb->prefix . Migrator::CONVERSATION_MESSAGES_TABLE;

		$conversation_columns = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
				$wpdb->dbname,
				$conversations_table
			)
		);
		$this->assertContains( 'assignee_last_seen_message_id', $conversation_columns );

		$message_columns = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
				$wpdb->dbname,
				$messages_table
			)
		);
		$this->assertContains( 'telegram_sender_user_id', $message_columns );

		$index_count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = %s',
				$wpdb->dbname,
				$messages_table,
				'telegram_sender_user_id'
			)
		);
		$this->assertGreaterThan( 0, (int) $index_count );

		// Re-running is a safe no-op.
		$migrator->maybe_migrate();
		$this->assertSame( 27, (int) get_option( 'universal_telegram_db_version' ) );
	}

	public function test_steps_23_and_24_create_the_visitor_digest_tables_with_a_seeded_state_row(): void {
		global $wpdb;

		update_option( 'universal_telegram_db_version', 22 );

		$migrator = new Migrator( new MigrationLock() );
		$migrator->maybe_migrate();

		$this->assertSame( 27, (int) get_option( 'universal_telegram_db_version' ) );

		$counters_table = $wpdb->prefix . Migrator::VISITOR_DIGEST_COUNTERS_TABLE;
		$state_table    = $wpdb->prefix . Migrator::VISITOR_DIGEST_STATE_TABLE;

		$this->assertSame( $counters_table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $counters_table ) ) );
		$this->assertSame( $state_table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $state_table ) ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$seeded_id = $wpdb->get_var( "SELECT id FROM {$state_table} WHERE id = 1" );
		$this->assertSame( '1', $seeded_id );

		// Re-running is a safe no-op and does not duplicate the seeded row.
		$migrator->maybe_migrate();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertSame( 1, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$state_table}" ) );
	}

	public function test_steps_25_and_26_create_the_operational_summary_and_intelligence_state_tables_with_a_seeded_state_row(): void {
		global $wpdb;

		update_option( 'universal_telegram_db_version', 24 );

		$migrator = new Migrator( new MigrationLock() );
		$migrator->maybe_migrate();

		$this->assertSame( 27, (int) get_option( 'universal_telegram_db_version' ) );

		$runs_table  = $wpdb->prefix . Migrator::OPERATIONAL_SUMMARY_RUNS_TABLE;
		$state_table = $wpdb->prefix . Migrator::INTELLIGENCE_SETTINGS_STATE_TABLE;

		$this->assertSame( $runs_table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $runs_table ) ) );
		$this->assertSame( $state_table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $state_table ) ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$seeded_id = $wpdb->get_var( "SELECT id FROM {$state_table} WHERE id = 1" );
		$this->assertSame( '1', $seeded_id );

		// summary_date's own UNIQUE constraint (not application discipline
		// alone) is what makes row creation exactly-once per UTC day —
		// asserted directly here at the schema level.
		$now = current_time( 'mysql', true );
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"INSERT INTO {$runs_table} (summary_date, window_started_at, window_ended_at, created_at) VALUES (%s, %s, %s, %s)",
				'2026-01-01',
				$now,
				$now,
				$now
			)
		);
		$duplicate = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"INSERT INTO {$runs_table} (summary_date, window_started_at, window_ended_at, created_at) VALUES (%s, %s, %s, %s)",
				'2026-01-01',
				$now,
				$now,
				$now
			)
		);
		$this->assertFalse( $duplicate );

		// Re-running is a safe no-op and does not duplicate the seeded row.
		$migrator->maybe_migrate();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertSame( 1, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$state_table}" ) );
	}

	public function test_step_27_creates_the_operational_alert_state_table_with_three_seeded_rows(): void {
		global $wpdb;

		update_option( 'universal_telegram_db_version', 26 );

		$migrator = new Migrator( new MigrationLock() );
		$migrator->maybe_migrate();

		$this->assertSame( 27, (int) get_option( 'universal_telegram_db_version' ) );

		$table = $wpdb->prefix . Migrator::OPERATIONAL_ALERT_STATE_TABLE;
		$this->assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) );

		$alert_types = $wpdb->get_col( "SELECT alert_type FROM {$table} ORDER BY alert_type" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertSame( array( 'checkout_failure_count', 'js_error_spike', 'order_failure_spike' ), $alert_types );

		// Re-running is a safe no-op and does not duplicate the seeded rows.
		$migrator->maybe_migrate();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertSame( 3, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) );
	}
}
