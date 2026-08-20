<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Events;

use UniversalTelegram\Events\RetentionCleanup;
use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Persistence\SchemaHealth;
use WP_UnitTestCase;

final class RetentionCleanupTest extends WP_UnitTestCase {

	private function insert_history_row( string $event_id, string $occurred_at ): void {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . Migrator::EVENT_HISTORY_TABLE,
			array(
				'event_id'              => $event_id,
				'event_type'            => 'wordpress.user_registered',
				'schema_version'        => 1,
				'occurred_at'           => $occurred_at,
				'source'                => 'wordpress_core',
				'projected_fields_json' => '{}',
				'created_at'            => current_time( 'mysql', true ),
			)
		);
	}

	private function insert_dispatch_log_row( int $rule_id, string $event_id, string $dispatched_at ): void {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . Migrator::DISPATCH_LOG_TABLE,
			array(
				'rule_id'       => $rule_id,
				'event_id'      => $event_id,
				'result'        => 'handed_off_to_m01',
				'dispatched_at' => $dispatched_at,
				'updated_at'    => $dispatched_at,
			)
		);
	}

	private function insert_marker( string $error_type, string $location_hash, string $status, string $occurred_at, ?string $promoted_at ): void {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . Migrator::FATAL_ERROR_MARKERS_TABLE,
			array(
				'error_type'    => $error_type,
				'location_hash' => $location_hash,
				'status'        => $status,
				'occurred_at'   => $occurred_at,
				'promoted_at'   => $promoted_at,
				'created_at'    => $occurred_at,
			)
		);
	}

	public function test_event_history_rows_older_than_the_window_are_deleted(): void {
		global $wpdb;

		$old_id = hash( 'sha256', 'old-event' );
		$new_id = hash( 'sha256', 'new-event' );

		$this->insert_history_row( $old_id, gmdate( 'Y-m-d H:i:s', time() - ( 100 * DAY_IN_SECONDS ) ) );
		$this->insert_history_row( $new_id, gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) );

		( new RetentionCleanup( new SchemaHealth(), 90, 90, 30 ) )->run();

		$table = $wpdb->prefix . Migrator::EVENT_HISTORY_TABLE;
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( "SELECT event_id FROM {$table} WHERE event_id = %s", $old_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertSame( $new_id, $wpdb->get_var( $wpdb->prepare( "SELECT event_id FROM {$table} WHERE event_id = %s", $new_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function test_dispatch_log_rows_older_than_the_window_are_deleted(): void {
		global $wpdb;

		$old_id = hash( 'sha256', 'old-dispatch' );
		$new_id = hash( 'sha256', 'new-dispatch' );

		$this->insert_dispatch_log_row( 1, $old_id, gmdate( 'Y-m-d H:i:s', time() - ( 100 * DAY_IN_SECONDS ) ) );
		$this->insert_dispatch_log_row( 1, $new_id, gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) );

		( new RetentionCleanup( new SchemaHealth(), 90, 90, 30 ) )->run();

		$table = $wpdb->prefix . Migrator::DISPATCH_LOG_TABLE;
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( "SELECT event_id FROM {$table} WHERE event_id = %s", $old_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertSame( $new_id, $wpdb->get_var( $wpdb->prepare( "SELECT event_id FROM {$table} WHERE event_id = %s", $new_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function test_promoted_markers_respect_their_own_retention_window(): void {
		global $wpdb;

		$this->insert_marker( 'E_ERROR', hash( 'sha256', 'old-loc' ), 'promoted', gmdate( 'Y-m-d H:i:s', time() - ( 40 * DAY_IN_SECONDS ) ), gmdate( 'Y-m-d H:i:s', time() - ( 35 * DAY_IN_SECONDS ) ) );
		$this->insert_marker( 'E_ERROR', hash( 'sha256', 'new-loc' ), 'promoted', gmdate( 'Y-m-d H:i:s', time() - ( 5 * DAY_IN_SECONDS ) ), gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) );

		( new RetentionCleanup( new SchemaHealth(), 90, 90, 30 ) )->run();

		$table = $wpdb->prefix . Migrator::FATAL_ERROR_MARKERS_TABLE;
		$remaining = $wpdb->get_col( "SELECT location_hash FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		$this->assertNotContains( hash( 'sha256', 'old-loc' ), $remaining );
		$this->assertContains( hash( 'sha256', 'new-loc' ), $remaining );
	}

	public function test_pending_markers_older_than_24_hours_are_dropped_and_counted(): void {
		global $wpdb;

		delete_option( RetentionCleanup::STALE_FATAL_MARKERS_DROPPED_OPTION );

		$this->insert_marker( 'E_ERROR', hash( 'sha256', 'stale-pending' ), 'pending', gmdate( 'Y-m-d H:i:s', time() - ( 2 * DAY_IN_SECONDS ) ), null );
		$this->insert_marker( 'E_ERROR', hash( 'sha256', 'fresh-pending' ), 'pending', gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ), null );

		( new RetentionCleanup( new SchemaHealth(), 90, 90, 30 ) )->run();

		$table     = $wpdb->prefix . Migrator::FATAL_ERROR_MARKERS_TABLE;
		$remaining = $wpdb->get_col( "SELECT location_hash FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		$this->assertNotContains( hash( 'sha256', 'stale-pending' ), $remaining );
		$this->assertContains( hash( 'sha256', 'fresh-pending' ), $remaining );
		$this->assertGreaterThanOrEqual( 1, (int) get_option( RetentionCleanup::STALE_FATAL_MARKERS_DROPPED_OPTION, 0 ) );
	}
}
