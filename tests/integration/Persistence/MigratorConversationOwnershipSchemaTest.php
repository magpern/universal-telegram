<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Persistence;

use UniversalTelegram\Persistence\MigrationLock;
use UniversalTelegram\Persistence\Migrator;
use WP_UnitTestCase;

/**
 * Schema-level evidence for step 16 (M06.3.1, ADR-0025): clean install
 * reaches db_version 16 with owner_user_id and the generated,
 * unique-indexed owner_active_slot column present, a from-15 upgrade adds
 * them, and a from-16 re-run is a safe no-op. Concurrency/duplicate-key
 * behaviour is proven at the repository layer (ConversationRepositoryTest)
 * — this file only exercises the migration step itself, via
 * INFORMATION_SCHEMA.
 */
final class MigratorConversationOwnershipSchemaTest extends WP_UnitTestCase {

	public function test_clean_install_reaches_db_version_16_with_the_new_columns_and_indexes(): void {
		global $wpdb;

		delete_option( 'universal_telegram_db_version' );

		$migrator = new Migrator( new MigrationLock() );
		$migrator->maybe_migrate();

		$this->assertSame( 36, (int) get_option( 'universal_telegram_db_version' ) );

		$table   = $wpdb->prefix . Migrator::CONVERSATIONS_TABLE;
		$columns = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
				$wpdb->dbname,
				$table
			)
		);
		$this->assertContains( 'owner_user_id', $columns );
		$this->assertContains( 'owner_active_slot', $columns );

		$indexes = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
				$wpdb->dbname,
				$table
			)
		);
		$this->assertContains( 'owner_active_slot', $indexes );
		$this->assertContains( 'owner_user_id', $indexes );
	}

	public function test_upgrade_from_db_version_15_adds_the_columns(): void {
		$migrator = new Migrator( new MigrationLock() );
		$migrator->maybe_migrate();

		update_option( 'universal_telegram_db_version', 15 );
		$migrator->maybe_migrate();

		$this->assertSame( 36, (int) get_option( 'universal_telegram_db_version' ) );
	}

	public function test_upgrade_from_db_version_16_is_a_safe_no_op(): void {
		$migrator = new Migrator( new MigrationLock() );
		$migrator->maybe_migrate();

		update_option( 'universal_telegram_db_version', 16 );
		$migrator->maybe_migrate();

		$this->assertSame( 36, (int) get_option( 'universal_telegram_db_version' ) );
	}

	public function test_owner_active_slot_unique_index_rejects_a_second_active_row_for_the_same_owner_and_bot(): void {
		global $wpdb;

		// Forces a real re-verification of the schema regardless of any
		// ambient db_version left by another test in the same process —
		// DDL is not transactional, so table_has_columns()'s own guard
		// inside step 16 makes this safe to call unconditionally.
		delete_option( 'universal_telegram_db_version' );
		$migrator = new Migrator( new MigrationLock() );
		$migrator->maybe_migrate();

		// A DDL statement (the ALTER TABLE inside step 16) implicitly
		// commits WP_UnitTestCase's own wrapping transaction; without a
		// forced reconnect here, this connection's subsequent statements
		// can retain a stale pre-ALTER view of the table in this test
		// environment specifically — a PHPUnit-transaction artifact, not a
		// production concern (a real request never runs a raw insert from
		// inside an already-open, long-lived transaction predating its own
		// migration).
		$wpdb->db_connect( true );

		$table = $wpdb->prefix . Migrator::CONVERSATIONS_TABLE;
		$now   = current_time( 'mysql', true );

		$base = array(
			'secret_hash'            => 'hash',
			'bot_id'                 => 1,
			'status'                 => 'open',
			'topic_creation_state'   => 'none',
			'ai_participation_state' => 'none',
			'consent_state'          => 'unknown',
			'owner_user_id'          => wp_rand( 100000, 999999 ),
			'created_at'             => $now,
			'updated_at'             => $now,
		);

		$first = $wpdb->insert(
			$table,
			array_merge( $base, array( 'conversation_uuid' => wp_generate_uuid4() ) )
		);
		$this->assertNotFalse( $first );

		$second = @$wpdb->insert( // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$table,
			array_merge( $base, array( 'conversation_uuid' => wp_generate_uuid4() ) )
		);
		$this->assertFalse( $second, 'A second active row for the same (owner_user_id, bot_id) must violate the unique index.' );
	}

	public function test_owner_active_slot_allows_a_new_active_row_once_the_prior_one_is_resolved(): void {
		global $wpdb;

		// Forces a real re-verification of the schema regardless of any
		// ambient db_version left by another test in the same process —
		// DDL is not transactional, so table_has_columns()'s own guard
		// inside step 16 makes this safe to call unconditionally.
		delete_option( 'universal_telegram_db_version' );
		$migrator = new Migrator( new MigrationLock() );
		$migrator->maybe_migrate();
		$wpdb->db_connect( true );

		$table = $wpdb->prefix . Migrator::CONVERSATIONS_TABLE;
		$now   = current_time( 'mysql', true );

		$base = array(
			'secret_hash'            => 'hash',
			'bot_id'                 => 1,
			'topic_creation_state'   => 'none',
			'ai_participation_state' => 'none',
			'consent_state'          => 'unknown',
			'owner_user_id'          => wp_rand( 100000, 999999 ),
			'created_at'             => $now,
			'updated_at'             => $now,
		);

		$resolved = $wpdb->insert(
			$table,
			array_merge(
				$base,
				array(
					'conversation_uuid' => wp_generate_uuid4(),
					'status'            => 'resolved',
				)
			)
		);
		$this->assertNotFalse( $resolved );

		$fresh = $wpdb->insert(
			$table,
			array_merge(
				$base,
				array(
					'conversation_uuid' => wp_generate_uuid4(),
					'status'            => 'new',
				)
			)
		);
		$this->assertNotFalse( $fresh, 'A resolved row must not occupy the owner_active_slot, freeing it for one fresh active conversation.' );
	}
}
