<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Persistence;

use UniversalTelegram\Persistence\MigrationLock;
use UniversalTelegram\Persistence\Migrator;
use WP_UnitTestCase;

/**
 * Schema-level evidence for steps 19–22 (M09, docs/adr/0028): clean
 * install reaches steps 19–22 (AI schema) along the way to the current
 * target version, with the AI config singleton row seeded,
 * the AI drafts table present with its lease/claim columns, the
 * conversations table's new nullable acknowledgement column, and
 * requested_by_user_id widened to nullable for account-deletion
 * anonymization (§4 retention table). The claim compare-and-set and
 * acknowledgement-gate semantics themselves are proven at the repository
 * layer instead — this file only exercises the migration steps, via
 * INFORMATION_SCHEMA and the seeded row.
 */
final class MigratorAiSchemaTest extends WP_UnitTestCase {

	public function test_clean_install_reaches_the_ai_schema_steps_with_seeded_config_row(): void {
		global $wpdb;

		delete_option( 'universal_telegram_db_version' );

		$migrator = new Migrator( new MigrationLock() );
		$migrator->maybe_migrate();

		$this->assertSame( 30, (int) get_option( 'universal_telegram_db_version' ) );

		$config_table   = $wpdb->prefix . Migrator::AI_CONFIG_TABLE;
		$config_columns = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
				$wpdb->dbname,
				$config_table
			)
		);
		foreach ( array( 'id', 'provider', 'model', 'api_key_ciphertext', 'enabled', 'ack_policy_version', 'ack_text', 'created_at', 'updated_at' ) as $expected ) {
			$this->assertContains( $expected, $config_columns );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name, never user input.
		$row = $wpdb->get_row( "SELECT * FROM {$config_table} WHERE id = 1", ARRAY_A );
		$this->assertNotNull( $row );
		$this->assertSame( 'openai', $row['provider'] );
		$this->assertSame( '0', (string) $row['enabled'] );
		$this->assertNull( $row['api_key_ciphertext'] );

		$drafts_table   = $wpdb->prefix . Migrator::AI_DRAFTS_TABLE;
		$drafts_columns = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
				$wpdb->dbname,
				$drafts_table
			)
		);
		foreach ( array( 'draft_uuid', 'conversation_id', 'status', 'lease_token', 'generation_lease_expires_at', 'claimed_at', 'attempt_count', 'job_reference' ) as $expected ) {
			$this->assertContains( $expected, $drafts_columns );
		}

		$conversations_columns = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
				$wpdb->dbname,
				$wpdb->prefix . Migrator::CONVERSATIONS_TABLE
			)
		);
		$this->assertContains( 'ai_ack_policy_version', $conversations_columns );

		// Re-running an already up-to-date schema must not error, must not
		// change the recorded version, and must not duplicate the seeded row.
		$migrator->maybe_migrate();
		$this->assertSame( 30, (int) get_option( 'universal_telegram_db_version' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name, never user input.
		$row_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$config_table}" );
		$this->assertSame( 1, $row_count );

		$requester_nullable = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s',
				$wpdb->dbname,
				$drafts_table,
				'requested_by_user_id'
			)
		);
		$this->assertSame( 'YES', $requester_nullable );
	}

	public function test_upgrade_from_db_version_18_is_a_safe_no_op_on_re_run(): void {
		$migrator = new Migrator( new MigrationLock() );
		$migrator->maybe_migrate();

		update_option( 'universal_telegram_db_version', 18 );
		$migrator->maybe_migrate();

		$this->assertSame( 30, (int) get_option( 'universal_telegram_db_version' ) );
	}
}
