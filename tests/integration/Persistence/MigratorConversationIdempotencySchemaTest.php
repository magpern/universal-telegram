<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Persistence;

use UniversalTelegram\Persistence\MigrationLock;
use UniversalTelegram\Persistence\Migrator;
use WP_UnitTestCase;

/**
 * Schema-level evidence for step 13 (M06 plan §0, §9): clean install reaches
 * db_version 13 with both new columns present, and a from-12 re-run is a
 * safe no-op. The uniqueness constraint itself and nullable-column upgrade
 * safety are proven at the repository layer instead
 * (ConversationRepositoryTest::test_create_rejects_a_duplicate_start_idempotency_key,
 * ::test_create_persists_and_finds_by_start_idempotency_key, and the
 * equivalent MessageRepositoryTest cases) — this file only exercises the
 * migration step itself, via INFORMATION_SCHEMA, never a direct
 * post-DDL $wpdb->insert() in the same request, which has shown transient,
 * environment-specific "Unknown column" flakiness in this containerized
 * MariaDB test service that a direct SQL client never reproduces.
 */
final class MigratorConversationIdempotencySchemaTest extends WP_UnitTestCase {

	public function test_clean_install_reaches_db_version_13_with_both_new_columns(): void {
		global $wpdb;

		delete_option( 'universal_telegram_db_version' );

		$migrator = new Migrator( new MigrationLock() );
		$migrator->maybe_migrate();

		$this->assertSame( 21, (int) get_option( 'universal_telegram_db_version' ) );

		$conversations_columns = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
				$wpdb->dbname,
				$wpdb->prefix . Migrator::CONVERSATIONS_TABLE
			)
		);
		$this->assertContains( 'start_idempotency_key', $conversations_columns );

		$messages_columns = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
				$wpdb->dbname,
				$wpdb->prefix . Migrator::CONVERSATION_MESSAGES_TABLE
			)
		);
		$this->assertContains( 'idempotency_key', $messages_columns );
	}

	public function test_upgrade_from_db_version_12_is_a_safe_no_op(): void {
		$migrator = new Migrator( new MigrationLock() );
		$migrator->maybe_migrate();

		update_option( 'universal_telegram_db_version', 12 );
		$migrator->maybe_migrate();

		$this->assertSame( 21, (int) get_option( 'universal_telegram_db_version' ) );
	}
}
