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

		$this->assertSame( 1, (int) get_option( 'universal_telegram_db_version' ) );
		$this->assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) );

		// Re-running an already up-to-date schema must not error and must
		// not change the recorded version.
		$migrator->maybe_migrate();
		$this->assertSame( 1, (int) get_option( 'universal_telegram_db_version' ) );
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
}
