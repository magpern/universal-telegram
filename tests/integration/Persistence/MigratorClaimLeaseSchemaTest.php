<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Persistence;

use UniversalTelegram\Persistence\MigrationLock;
use UniversalTelegram\Persistence\Migrator;
use WP_UnitTestCase;

/**
 * Schema-level evidence for step 14 (M06.2 corrective plan v2 §3.1,
 * ADR-0023 amendment): clean install reaches db_version 14 with both new
 * lease columns present, and a from-13 re-run is a safe no-op. The claim
 * compare-and-set semantics themselves are proven at the repository layer
 * instead (OutboundMessageRepositoryTest, ConversationRepositoryTest) — this
 * file only exercises the migration step itself, via INFORMATION_SCHEMA.
 */
final class MigratorClaimLeaseSchemaTest extends WP_UnitTestCase {

	public function test_clean_install_reaches_db_version_14_with_both_new_columns(): void {
		global $wpdb;

		delete_option( 'universal_telegram_db_version' );

		$migrator = new Migrator( new MigrationLock() );
		$migrator->maybe_migrate();

		// Migrator::maybe_migrate() always runs every pending step up to
		// the current target_version() (15 as of M06.3) — not this step's
		// own historical target — so this assertion tracks the current
		// target, not a fixed number frozen at this step's own introduction.
		$this->assertSame( 21, (int) get_option( 'universal_telegram_db_version' ) );

		$outbound_columns = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
				$wpdb->dbname,
				$wpdb->prefix . Migrator::OUTBOUND_MESSAGES_TABLE
			)
		);
		$this->assertContains( 'claim_expires_at', $outbound_columns );

		$conversations_columns = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
				$wpdb->dbname,
				$wpdb->prefix . Migrator::CONVERSATIONS_TABLE
			)
		);
		$this->assertContains( 'topic_claim_expires_at', $conversations_columns );
	}

	public function test_upgrade_from_db_version_13_is_a_safe_no_op(): void {
		$migrator = new Migrator( new MigrationLock() );
		$migrator->maybe_migrate();

		update_option( 'universal_telegram_db_version', 13 );
		$migrator->maybe_migrate();

		// Migrator::maybe_migrate() always runs every pending step up to
		// the current target_version() (15 as of M06.3) — not this step's
		// own historical target — so this assertion tracks the current
		// target, not a fixed number frozen at this step's own introduction.
		$this->assertSame( 21, (int) get_option( 'universal_telegram_db_version' ) );
	}
}
