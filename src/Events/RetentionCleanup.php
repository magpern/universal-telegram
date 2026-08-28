<?php
/**
 * Retention-based cleanup of event history, dispatch log, and fatal markers.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Events;

use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Persistence\SchemaHealth;

/**
 * A recurring Action Scheduler action, independent of the queue's own
 * job-handler contract: deletes event_history rows, notification_dispatch_log
 * rows, and fatal_error_markers rows past their own configured or fixed
 * retention windows (M02 plan §5.5, §8.6). Runs each deletion as a bounded
 * `DELETE ... LIMIT 500` loop to avoid long table locks.
 */
final class RetentionCleanup {

	public const HOOK = 'universal_telegram_events_retention_cleanup';

	public const STALE_FATAL_MARKERS_DROPPED_OPTION = 'universal_telegram_stale_fatal_markers_dropped_count';

	private const BATCH_SIZE                   = 500;
	private const PENDING_MARKER_CEILING_HOURS = 24;

	/**
	 * Constructor.
	 *
	 * @param SchemaHealth $schema_health                Checked before every pass.
	 * @param int          $event_retention_days          event_history retention window, in days.
	 * @param int          $dispatch_log_retention_days   notification_dispatch_log retention window, in days.
	 * @param int          $fatal_marker_retention_days   Retention window for promoted fatal_error_markers rows, in days.
	 */
	public function __construct(
		private readonly SchemaHealth $schema_health,
		private readonly int $event_retention_days = 90,
		private readonly int $dispatch_log_retention_days = 90,
		private readonly int $fatal_marker_retention_days = 30
	) {}

	/**
	 * Runs one cleanup pass.
	 */
	public function run(): void {
		if ( ! $this->schema_health->is_available() ) {
			return;
		}

		$this->delete_older_than( Migrator::EVENT_HISTORY_TABLE, 'occurred_at', $this->event_retention_days );
		$this->delete_older_than( Migrator::DISPATCH_LOG_TABLE, 'dispatched_at', $this->dispatch_log_retention_days );
		$this->delete_promoted_markers_older_than( $this->fatal_marker_retention_days );
		$this->delete_stale_pending_markers();
	}

	/**
	 * Deletes rows older than a configured window, in bounded batches.
	 *
	 * @param string $table_name The unprefixed table name.
	 * @param string $column     The timestamp column to compare.
	 * @param int    $days       The retention window, in days.
	 */
	private function delete_older_than( string $table_name, string $column, int $days ): void {
		global $wpdb;

		$table     = $wpdb->prefix . $table_name;
		$threshold = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		do {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table/column names, never user input.
			$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE {$column} < %s LIMIT %d", $threshold, self::BATCH_SIZE ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} while ( is_int( $deleted ) && $deleted > 0 );
	}

	/**
	 * Deletes promoted fatal-error markers older than the configured
	 * retention window.
	 *
	 * @param int $days The retention window, in days.
	 */
	private function delete_promoted_markers_older_than( int $days ): void {
		global $wpdb;

		$table     = $wpdb->prefix . Migrator::FATAL_ERROR_MARKERS_TABLE;
		$threshold = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		do {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name, never user input.
			$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE status = 'promoted' AND promoted_at < %s LIMIT %d", $threshold, self::BATCH_SIZE ) );
		} while ( is_int( $deleted ) && $deleted > 0 );
	}

	/**
	 * Deletes pending fatal-error markers older than the fixed 24-hour
	 * ceiling — too old to have been usefully promoted — incrementing the
	 * stale-drop diagnostics counter for each dropped row (M02 plan §8.6).
	 */
	private function delete_stale_pending_markers(): void {
		global $wpdb;

		$table     = $wpdb->prefix . Migrator::FATAL_ERROR_MARKERS_TABLE;
		$threshold = gmdate( 'Y-m-d H:i:s', time() - ( self::PENDING_MARKER_CEILING_HOURS * HOUR_IN_SECONDS ) );
		$dropped   = 0;

		do {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name, never user input.
			$deleted  = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE status = 'pending' AND occurred_at < %s LIMIT %d", $threshold, self::BATCH_SIZE ) );
			$dropped += is_int( $deleted ) ? $deleted : 0;
		} while ( is_int( $deleted ) && $deleted > 0 );

		if ( $dropped > 0 ) {
			$current = (int) get_option( self::STALE_FATAL_MARKERS_DROPPED_OPTION, 0 );
			update_option( self::STALE_FATAL_MARKERS_DROPPED_OPTION, $current + $dropped, false );
		}
	}
}
