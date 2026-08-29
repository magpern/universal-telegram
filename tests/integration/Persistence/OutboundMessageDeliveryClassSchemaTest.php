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
 * ADR-0045 §1: `outbound_messages.delivery_class` is additive,
 * `NOT NULL DEFAULT 'standard'`, on both fresh and upgraded installs;
 * `db_version` reaches 38 with no data change.
 */
final class OutboundMessageDeliveryClassSchemaTest extends WP_UnitTestCase {

	public function test_fresh_install_has_the_column_defaulting_to_standard(): void {
		global $wpdb;

		delete_option( 'universal_telegram_db_version' );
		( new Migrator( new MigrationLock() ) )->maybe_migrate();

		$this->assertSame( 38, (int) get_option( 'universal_telegram_db_version' ) );

		$table  = $wpdb->prefix . Migrator::OUTBOUND_MESSAGES_TABLE;
		$column = $wpdb->get_row( "SHOW COLUMNS FROM {$table} LIKE 'delivery_class'", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->assertNotNull( $column );
		$this->assertSame( 'NO', $column['Null'] );
		$this->assertSame( 'standard', $column['Default'] );

		// A row inserted without the column is `standard`.
		$wpdb->query( "INSERT INTO {$table} (message_uuid, bot_id, destination_id, status, created_at, updated_at) VALUES ('11111111-1111-1111-1111-111111111111', 1, 1, 'pending', '2026-01-01 00:00:00', '2026-01-01 00:00:00')" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->assertSame( 'standard', $wpdb->get_var( "SELECT delivery_class FROM {$table} WHERE message_uuid = '11111111-1111-1111-1111-111111111111'" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	public function test_upgrade_from_37_adds_the_column_without_touching_existing_rows(): void {
		global $wpdb;

		delete_option( 'universal_telegram_migration_lock' );
		( new Migrator( new MigrationLock() ) )->maybe_migrate();

		$table = $wpdb->prefix . Migrator::OUTBOUND_MESSAGES_TABLE;

		// Simulate a v37 install: drop the column and wind the version back.
		$wpdb->query( "ALTER TABLE {$table} DROP COLUMN delivery_class" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "INSERT INTO {$table} (message_uuid, bot_id, destination_id, status, created_at, updated_at) VALUES ('22222222-2222-2222-2222-222222222222', 9, 9, 'sent', '2026-01-01 00:00:00', '2026-01-01 00:00:00')" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		update_option( 'universal_telegram_db_version', 37 );

		( new Migrator( new MigrationLock() ) )->maybe_migrate();

		$this->assertSame( 38, (int) get_option( 'universal_telegram_db_version' ) );
		$this->assertNotNull( $wpdb->get_row( "SHOW COLUMNS FROM {$table} LIKE 'delivery_class'", ARRAY_A ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$row = $wpdb->get_row( "SELECT status, delivery_class FROM {$table} WHERE message_uuid = '22222222-2222-2222-2222-222222222222'", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->assertSame( 'sent', $row['status'], 'the pre-existing row is untouched' );
		$this->assertSame( 'standard', $row['delivery_class'], 'the backfilled default is standard' );

		// Idempotent re-run.
		( new Migrator( new MigrationLock() ) )->maybe_migrate();
		$this->assertSame( 38, (int) get_option( 'universal_telegram_db_version' ) );
	}
}
