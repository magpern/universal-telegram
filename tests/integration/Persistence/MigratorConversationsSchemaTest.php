<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Persistence;

use UniversalTelegram\Persistence\MigrationLock;
use UniversalTelegram\Persistence\Migrator;
use WP_UnitTestCase;

final class MigratorConversationsSchemaTest extends WP_UnitTestCase {

	public function test_steps_11_and_12_create_the_two_conversation_tables(): void {
		global $wpdb;

		$tables = array(
			Migrator::CONVERSATIONS_TABLE,
			Migrator::CONVERSATION_MESSAGES_TABLE,
		);

		foreach ( $tables as $table_name ) {
			$table = $wpdb->prefix . $table_name;
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name, never user input.
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		}
		delete_option( 'universal_telegram_db_version' );

		$migrator = new Migrator( new MigrationLock() );
		$migrator->maybe_migrate();

		$this->assertSame( 13, (int) get_option( 'universal_telegram_db_version' ) );

		foreach ( $tables as $table_name ) {
			$table = $wpdb->prefix . $table_name;
			$this->assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ), "Missing table {$table}" );
		}

		// Re-running an already up-to-date schema is a safe no-op.
		$migrator->maybe_migrate();
		$this->assertSame( 13, (int) get_option( 'universal_telegram_db_version' ) );
	}

	public function test_conversations_table_has_the_documented_unique_uuid_key(): void {
		global $wpdb;

		$table   = $wpdb->prefix . Migrator::CONVERSATIONS_TABLE;
		$indexes = $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Key_name = 'conversation_uuid'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$this->assertNotEmpty( $indexes );
		$this->assertSame( '0', (string) $indexes[0]->Non_unique );
	}

	public function test_conversations_table_has_the_topic_creation_state_index(): void {
		global $wpdb;

		$table   = $wpdb->prefix . Migrator::CONVERSATIONS_TABLE;
		$indexes = $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Key_name = 'topic_creation_state'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$this->assertNotEmpty( $indexes );
	}

	public function test_conversation_messages_table_has_the_documented_unique_message_uuid_key(): void {
		global $wpdb;

		$table   = $wpdb->prefix . Migrator::CONVERSATION_MESSAGES_TABLE;
		$indexes = $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Key_name = 'message_uuid'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$this->assertNotEmpty( $indexes );
		$this->assertSame( '0', (string) $indexes[0]->Non_unique );
	}

	public function test_conversation_messages_table_has_the_conversation_created_cursor_index(): void {
		global $wpdb;

		$table   = $wpdb->prefix . Migrator::CONVERSATION_MESSAGES_TABLE;
		$indexes = $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Key_name = 'conversation_created'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$this->assertCount( 2, $indexes );
	}
}
