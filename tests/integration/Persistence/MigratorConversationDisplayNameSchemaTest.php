<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Persistence;

use UniversalTelegram\Persistence\MigrationLock;
use UniversalTelegram\Persistence\Migrator;
use WP_UnitTestCase;

/**
 * Schema-level evidence for step 15 (M06.3, ADR-0024): clean install
 * reaches db_version 15 with the new nullable display_name_ciphertext
 * column present, a from-14 upgrade adds it, and a from-15 re-run is a
 * safe no-op. Encryption/write-once/decrypt semantics are proven at the
 * repository layer instead (ConversationRepositoryTest) — this file only
 * exercises the migration step itself, via INFORMATION_SCHEMA.
 */
final class MigratorConversationDisplayNameSchemaTest extends WP_UnitTestCase {

	public function test_clean_install_reaches_db_version_15_with_the_new_column(): void {
		global $wpdb;

		delete_option( 'universal_telegram_db_version' );

		$migrator = new Migrator( new MigrationLock() );
		$migrator->maybe_migrate();

		$this->assertSame( 22, (int) get_option( 'universal_telegram_db_version' ) );

		$columns = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
				$wpdb->dbname,
				$wpdb->prefix . Migrator::CONVERSATIONS_TABLE
			)
		);
		$this->assertContains( 'display_name_ciphertext', $columns );
	}

	public function test_upgrade_from_db_version_14_adds_the_column(): void {
		$migrator = new Migrator( new MigrationLock() );
		$migrator->maybe_migrate();

		update_option( 'universal_telegram_db_version', 14 );
		$migrator->maybe_migrate();

		$this->assertSame( 22, (int) get_option( 'universal_telegram_db_version' ) );
	}

	public function test_upgrade_from_db_version_15_is_a_safe_no_op(): void {
		$migrator = new Migrator( new MigrationLock() );
		$migrator->maybe_migrate();

		update_option( 'universal_telegram_db_version', 15 );
		$migrator->maybe_migrate();

		$this->assertSame( 22, (int) get_option( 'universal_telegram_db_version' ) );
	}
}
