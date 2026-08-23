<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Persistence;

use UniversalTelegram\Persistence\MigrationLock;
use UniversalTelegram\Persistence\Migrator;
use WP_UnitTestCase;

final class MigratorEventsSchemaTest extends WP_UnitTestCase {

	public function test_steps_8_through_10_create_the_four_new_tables(): void {
		global $wpdb;

		$tables = array(
			Migrator::EVENT_HISTORY_TABLE,
			Migrator::FATAL_ERROR_MARKERS_TABLE,
			Migrator::NOTIFICATION_RULES_TABLE,
			Migrator::DISPATCH_LOG_TABLE,
		);

		foreach ( $tables as $table_name ) {
			$table = $wpdb->prefix . $table_name;
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name, never user input.
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		}
		delete_option( 'universal_telegram_db_version' );

		$migrator = new Migrator( new MigrationLock() );
		$migrator->maybe_migrate();

		$this->assertSame( 22, (int) get_option( 'universal_telegram_db_version' ) );

		foreach ( $tables as $table_name ) {
			$table = $wpdb->prefix . $table_name;
			$this->assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ), "Missing table {$table}" );
		}

		// Re-running an already up-to-date schema is a safe no-op.
		$migrator->maybe_migrate();
		$this->assertSame( 22, (int) get_option( 'universal_telegram_db_version' ) );
	}

	public function test_event_history_table_has_the_documented_unique_event_id_key(): void {
		global $wpdb;

		$table   = $wpdb->prefix . Migrator::EVENT_HISTORY_TABLE;
		$indexes = $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Key_name = 'event_id'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$this->assertNotEmpty( $indexes );
		$this->assertSame( '0', (string) $indexes[0]->Non_unique );
	}

	public function test_fatal_error_markers_table_has_the_documented_unique_composite_key(): void {
		global $wpdb;

		$table   = $wpdb->prefix . Migrator::FATAL_ERROR_MARKERS_TABLE;
		$indexes = $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Key_name = 'error_type_location'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$this->assertCount( 2, $indexes );
		$this->assertSame( '0', (string) $indexes[0]->Non_unique );
	}

	public function test_dispatch_log_table_has_the_documented_unique_rule_event_key(): void {
		global $wpdb;

		$table   = $wpdb->prefix . Migrator::DISPATCH_LOG_TABLE;
		$indexes = $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Key_name = 'rule_event'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$this->assertCount( 2, $indexes );
		$this->assertSame( '0', (string) $indexes[0]->Non_unique );
	}

	public function test_notification_rules_table_has_the_evaluation_ordering_index(): void {
		global $wpdb;

		$table   = $wpdb->prefix . Migrator::NOTIFICATION_RULES_TABLE;
		$indexes = $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Key_name = 'event_type_enabled_priority'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$this->assertCount( 4, $indexes );
	}
}
