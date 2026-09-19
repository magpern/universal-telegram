<?php
/**
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Tests\Integration\Persistence;

use UniversalTelegram\Persistence\MigrationLock;
use UniversalTelegram\Persistence\Migrator;
use WP_UnitTestCase;

/**
 * ADR-0046: migration 40 adds the nullable `correlation_token` column and the
 * `(destination_id, telegram_message_id)` lookup index — additive, repeat-safe, valid
 * on fresh and upgraded (39) installs, and with no index on `correlation_token`.
 */
final class OutboundMessageCorrelationSchemaTest extends WP_UnitTestCase {

	private function table(): string {
		global $wpdb;

		return $wpdb->prefix . Migrator::OUTBOUND_MESSAGES_TABLE;
	}

	/**
	 * @return array<int, string> Column names of the index, in order.
	 */
	private function index_columns( string $index ): array {
		global $wpdb;

		$table = $this->table();
		$rows  = $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Key_name = '{$index}'", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		usort( $rows, static fn ( array $a, array $b ): int => (int) $a['Seq_in_index'] <=> (int) $b['Seq_in_index'] );

		return array_map( static fn ( array $row ): string => (string) $row['Column_name'], $rows );
	}

	public function test_fresh_install_has_the_nullable_column_and_the_composite_index(): void {
		global $wpdb;

		delete_option( 'universal_telegram_db_version' );
		( new Migrator( new MigrationLock() ) )->maybe_migrate();

		$this->assertSame( 40, (int) get_option( 'universal_telegram_db_version' ) );

		$table  = $this->table();
		$column = $wpdb->get_row( "SHOW COLUMNS FROM {$table} LIKE 'correlation_token'", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->assertNotNull( $column );
		$this->assertSame( 'YES', $column['Null'] );
		$this->assertSame( 'varchar(191)', $column['Type'] );

		$this->assertSame( array( 'destination_id', 'telegram_message_id' ), $this->index_columns( 'idx_destination_telegram_message' ) );
	}

	public function test_no_index_on_the_correlation_token_is_added(): void {
		global $wpdb;

		delete_option( 'universal_telegram_db_version' );
		( new Migrator( new MigrationLock() ) )->maybe_migrate();

		$table = $this->table();
		$rows  = $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Column_name = 'correlation_token'", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$this->assertSame( array(), $rows );
	}

	public function test_upgrade_from_39_is_additive_and_leaves_existing_rows_untouched(): void {
		global $wpdb;

		delete_option( 'universal_telegram_migration_lock' );
		( new Migrator( new MigrationLock() ) )->maybe_migrate();

		$table = $this->table();

		// Simulate a v39 install: remove what step 40 added and wind the version back.
		$wpdb->query( "ALTER TABLE {$table} DROP INDEX idx_destination_telegram_message" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "ALTER TABLE {$table} DROP COLUMN correlation_token" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "INSERT INTO {$table} (message_uuid, bot_id, destination_id, telegram_message_id, status, created_at, updated_at) VALUES ('66666666-6666-6666-6666-666666666666', 9, 9, 321, 'sent', '2026-01-01 00:00:00', '2026-01-01 00:00:00')" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		update_option( 'universal_telegram_db_version', 39 );

		( new Migrator( new MigrationLock() ) )->maybe_migrate();

		$this->assertSame( 40, (int) get_option( 'universal_telegram_db_version' ) );
		$this->assertNotNull( $wpdb->get_row( "SHOW COLUMNS FROM {$table} LIKE 'correlation_token'", ARRAY_A ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->assertSame( array( 'destination_id', 'telegram_message_id' ), $this->index_columns( 'idx_destination_telegram_message' ) );

		$row = $wpdb->get_row( "SELECT status, telegram_message_id, correlation_token FROM {$table} WHERE message_uuid = '66666666-6666-6666-6666-666666666666'", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->assertSame( 'sent', $row['status'] );
		$this->assertSame( '321', (string) $row['telegram_message_id'] );
		$this->assertNull( $row['correlation_token'] );
	}

	public function test_step_is_repeat_safe_when_the_column_and_index_already_exist(): void {
		delete_option( 'universal_telegram_migration_lock' );
		( new Migrator( new MigrationLock() ) )->maybe_migrate();

		// Force the step to run again over an already-migrated schema.
		update_option( 'universal_telegram_db_version', 39 );
		( new Migrator( new MigrationLock() ) )->maybe_migrate();

		$this->assertSame( 40, (int) get_option( 'universal_telegram_db_version' ) );
		$this->assertSame( array( 'destination_id', 'telegram_message_id' ), $this->index_columns( 'idx_destination_telegram_message' ) );
	}
}
