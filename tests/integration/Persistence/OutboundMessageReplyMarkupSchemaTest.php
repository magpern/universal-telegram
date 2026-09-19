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
 * `outbound_messages.reply_markup_ciphertext` is additive and nullable, on
 * both fresh and upgraded installs; `db_version` reaches 40 with no data
 * change to any pre-existing row.
 */
final class OutboundMessageReplyMarkupSchemaTest extends WP_UnitTestCase {

	public function test_fresh_install_has_the_nullable_column(): void {
		global $wpdb;

		delete_option( 'universal_telegram_db_version' );
		( new Migrator( new MigrationLock() ) )->maybe_migrate();

		$this->assertSame( 40, (int) get_option( 'universal_telegram_db_version' ) );

		$table  = $wpdb->prefix . Migrator::OUTBOUND_MESSAGES_TABLE;
		$column = $wpdb->get_row( "SHOW COLUMNS FROM {$table} LIKE 'reply_markup_ciphertext'", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->assertNotNull( $column );
		$this->assertSame( 'YES', $column['Null'] );

		// A row inserted without the column has a null reply_markup.
		$wpdb->query( "INSERT INTO {$table} (message_uuid, bot_id, destination_id, status, created_at, updated_at) VALUES ('44444444-4444-4444-4444-444444444444', 1, 1, 'pending', '2026-01-01 00:00:00', '2026-01-01 00:00:00')" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->assertNull( $wpdb->get_var( "SELECT reply_markup_ciphertext FROM {$table} WHERE message_uuid = '44444444-4444-4444-4444-444444444444'" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	public function test_upgrade_from_38_adds_the_column_without_touching_existing_rows(): void {
		global $wpdb;

		delete_option( 'universal_telegram_migration_lock' );
		( new Migrator( new MigrationLock() ) )->maybe_migrate();

		$table = $wpdb->prefix . Migrator::OUTBOUND_MESSAGES_TABLE;

		// Simulate a v38 install: drop the column and wind the version back.
		$wpdb->query( "ALTER TABLE {$table} DROP COLUMN reply_markup_ciphertext" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "INSERT INTO {$table} (message_uuid, bot_id, destination_id, status, created_at, updated_at) VALUES ('55555555-5555-5555-5555-555555555555', 9, 9, 'sent', '2026-01-01 00:00:00', '2026-01-01 00:00:00')" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		update_option( 'universal_telegram_db_version', 38 );

		( new Migrator( new MigrationLock() ) )->maybe_migrate();

		$this->assertSame( 40, (int) get_option( 'universal_telegram_db_version' ) );
		$this->assertNotNull( $wpdb->get_row( "SHOW COLUMNS FROM {$table} LIKE 'reply_markup_ciphertext'", ARRAY_A ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$row = $wpdb->get_row( "SELECT status, reply_markup_ciphertext FROM {$table} WHERE message_uuid = '55555555-5555-5555-5555-555555555555'", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->assertSame( 'sent', $row['status'], 'the pre-existing row is untouched' );
		$this->assertNull( $row['reply_markup_ciphertext'] );

		// Idempotent re-run.
		( new Migrator( new MigrationLock() ) )->maybe_migrate();
		$this->assertSame( 40, (int) get_option( 'universal_telegram_db_version' ) );
	}
}
