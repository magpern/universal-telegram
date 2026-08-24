<?php
/**
 * Visitor digest aggregation-window counter persistence.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Automations\Digest;

use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Persistence\SchemaHealth;

/**
 * One row per (window_started_at, category, page_type) bucket — never one
 * row per event (docs/plans/m11a-visitor-activity-digests-plan-v1.md §5).
 * page_type is always a non-null string, never SQL NULL: MySQL treats every
 * NULL in a unique key as distinct from every other NULL (the same gap
 * DestinationRepositoryTest documents for message_thread_id), which would
 * silently defeat the increment's own ON DUPLICATE KEY UPDATE collapse for
 * every category besides page_views. An empty string ('') is used instead
 * wherever no page-type breakdown applies, so the unique key
 * (window_started_at, category, page_type) always correctly collapses
 * repeated increments into one row.
 */
final class VisitorDigestCounterRepository {

	/**
	 * Constructor.
	 *
	 * @param SchemaHealth $schema_health Checked before every operation.
	 */
	public function __construct( private readonly SchemaHealth $schema_health ) {}

	/**
	 * Atomically increments one (window, category, page_type) bucket by
	 * one, creating the row on its first occurrence within the window.
	 *
	 * @param string $window_started_at The open window's own timestamp.
	 * @param string $category          One of the fixed category strings.
	 * @param string $page_type         The page-type breakdown value, or '' when not applicable.
	 */
	public function increment( string $window_started_at, string $category, string $page_type = '' ): void {
		if ( ! $this->schema_health->is_available() ) {
			return;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::VISITOR_DIGEST_COUNTERS_TABLE;
		$now   = current_time( 'mysql', true );

		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name, never user input.
				"INSERT INTO {$table} (window_started_at, category, page_type, event_count, last_event_at) VALUES (%s, %s, %s, 1, %s)
					ON DUPLICATE KEY UPDATE event_count = event_count + 1, last_event_at = VALUES(last_event_at)",
				$window_started_at,
				$category,
				$page_type,
				$now
			)
		);
	}

	/**
	 * Every counter row belonging to the given window, in insertion order.
	 * Used by the sweep (WP4) to both sum for threshold evaluation and
	 * render the digest's category/page-type breakdown.
	 *
	 * @param string $window_started_at The window's own timestamp.
	 *
	 * @return array<int, array{category: string, page_type: string, event_count: int}>
	 */
	public function for_window( string $window_started_at ): array {
		if ( ! $this->schema_health->is_available() ) {
			return array();
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::VISITOR_DIGEST_COUNTERS_TABLE;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT category, page_type, event_count FROM {$table} WHERE window_started_at = %s ORDER BY id ASC",
				$window_started_at
			),
			ARRAY_A
		);

		if ( null === $rows ) {
			return array();
		}

		return array_map(
			static fn( array $row ): array => array(
				'category'    => (string) $row['category'],
				'page_type'   => (string) $row['page_type'],
				'event_count' => (int) $row['event_count'],
			),
			$rows
		);
	}

	/**
	 * The total event count across every category/page_type bucket in the
	 * given window — the value the sweep compares against
	 * visitor_digest_threshold.
	 *
	 * @param string $window_started_at The window's own timestamp.
	 *
	 * @return int
	 */
	public function sum_for_window( string $window_started_at ): int {
		if ( ! $this->schema_health->is_available() ) {
			return 0;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::VISITOR_DIGEST_COUNTERS_TABLE;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COALESCE(SUM(event_count), 0) FROM {$table} WHERE window_started_at = %s",
				$window_started_at
			)
		);
	}

	/**
	 * Deletes every counter row belonging to the given window, once the
	 * sweep has successfully handed the digest off for delivery.
	 *
	 * @param string $window_started_at The window's own timestamp.
	 */
	public function delete_for_window( string $window_started_at ): void {
		if ( ! $this->schema_health->is_available() ) {
			return;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::VISITOR_DIGEST_COUNTERS_TABLE;

		$wpdb->delete( $table, array( 'window_started_at' => $window_started_at ), array( '%s' ) );
	}
}
