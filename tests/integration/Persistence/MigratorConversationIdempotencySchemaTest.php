<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Persistence;

use UniversalTelegram\Persistence\MigrationLock;
use UniversalTelegram\Persistence\Migrator;
use WP_UnitTestCase;

final class MigratorConversationIdempotencySchemaTest extends WP_UnitTestCase {

	public function test_clean_install_reaches_db_version_13_with_both_new_columns(): void {
		global $wpdb;

		delete_option( 'universal_telegram_db_version' );

		$migrator = new Migrator( new MigrationLock() );
		$migrator->maybe_migrate();

		$this->assertSame( 13, (int) get_option( 'universal_telegram_db_version' ) );

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

	public function test_upgrade_from_db_version_12_reaches_13_and_leaves_existing_rows_intact(): void {
		global $wpdb;

		// Clean install first, to guarantee the schema exists (a fresh test
		// DB has never run the migrator), then roll the recorded version
		// back to 12 to simulate a pre-M06 install that already has both
		// conversation tables but not yet the idempotency columns.
		$migrator = new Migrator( new MigrationLock() );
		$migrator->maybe_migrate();

		$conversations_table = $wpdb->prefix . Migrator::CONVERSATIONS_TABLE;
		$messages_table      = $wpdb->prefix . Migrator::CONVERSATION_MESSAGES_TABLE;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "ALTER TABLE {$conversations_table} DROP COLUMN start_idempotency_key" );
		$wpdb->query( "ALTER TABLE {$messages_table} DROP COLUMN idempotency_key" );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$conversations = new \UniversalTelegram\Conversations\ConversationRepository( new \UniversalTelegram\Persistence\SchemaHealth() );
		$pre_existing  = $conversations->create( wp_generate_uuid4(), 'hashed-secret', 1, null );
		$this->assertNotNull( $pre_existing );

		update_option( 'universal_telegram_db_version', 12 );

		$migrator->maybe_migrate();

		$this->assertSame( 13, (int) get_option( 'universal_telegram_db_version' ) );

		$found = $conversations->find( $pre_existing->id() );
		$this->assertNotNull( $found );
		$this->assertSame( $pre_existing->conversation_uuid(), $found->conversation_uuid() );
		$this->assertNull( $found->start_idempotency_key() );

		$columns = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
				$wpdb->dbname,
				$conversations_table
			)
		);
		$this->assertContains( 'start_idempotency_key', $columns );
	}

	public function test_start_idempotency_key_column_enforces_uniqueness_at_the_database_layer(): void {
		global $wpdb;

		$migrator = new Migrator( new MigrationLock() );
		$migrator->maybe_migrate();

		$table = $wpdb->prefix . Migrator::CONVERSATIONS_TABLE;
		$now   = current_time( 'mysql', true );

		$wpdb->insert(
			$table,
			array(
				'conversation_uuid'     => wp_generate_uuid4(),
				'bot_id'                => 1,
				'status'                => 'new',
				'topic_creation_state'  => 'none',
				'ai_participation_state' => 'none',
				'consent_state'         => 'unknown',
				'start_idempotency_key' => 'duplicate-key',
				'created_at'            => $now,
				'updated_at'            => $now,
			)
		);

		$second = $wpdb->insert(
			$table,
			array(
				'conversation_uuid'     => wp_generate_uuid4(),
				'bot_id'                => 1,
				'status'                => 'new',
				'topic_creation_state'  => 'none',
				'ai_participation_state' => 'none',
				'consent_state'         => 'unknown',
				'start_idempotency_key' => 'duplicate-key',
				'created_at'            => $now,
				'updated_at'            => $now,
			)
		);

		$this->assertFalse( $second );

		// Multiple NULLs must remain allowed (pre-migration/never-set rows).
		$third = $wpdb->insert(
			$table,
			array(
				'conversation_uuid'    => wp_generate_uuid4(),
				'bot_id'               => 1,
				'status'               => 'new',
				'topic_creation_state' => 'none',
				'ai_participation_state' => 'none',
				'consent_state'        => 'unknown',
				'created_at'           => $now,
				'updated_at'           => $now,
			)
		);
		$fourth = $wpdb->insert(
			$table,
			array(
				'conversation_uuid'    => wp_generate_uuid4(),
				'bot_id'               => 1,
				'status'               => 'new',
				'topic_creation_state' => 'none',
				'ai_participation_state' => 'none',
				'consent_state'        => 'unknown',
				'created_at'           => $now,
				'updated_at'           => $now,
			)
		);

		$this->assertNotFalse( $third );
		$this->assertNotFalse( $fourth );
	}
}
