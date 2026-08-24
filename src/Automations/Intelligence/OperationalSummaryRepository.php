<?php
/**
 * Operational-summary daily aggregate persistence and event_history queries.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Automations\Intelligence;

use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Persistence\SchemaHealth;

/**
 * Owns universal_telegram_operational_summary_runs (M11B plan §4, step 25):
 * one row per UTC calendar day, keyed by summary_date's own UNIQUE
 * constraint. Row creation is structurally exactly-once — a crash or a
 * retried sweep tick always resolves to the same row, via the same
 * INSERT ... ON DUPLICATE KEY UPDATE idiom M11A's own aggregator already
 * uses for window_started_at. Also owns the bounded event_history
 * aggregation queries the sweep needs to populate that row's counts —
 * every query is a COUNT filtered by event_type and a bounded occurred_at
 * range, never an unbounded scan.
 */
final class OperationalSummaryRepository {

	/**
	 * Constructor.
	 *
	 * @param SchemaHealth $schema_health Checked before every operation.
	 */
	public function __construct( private readonly SchemaHealth $schema_health ) {}

	/**
	 * Creates the row for the given UTC calendar day if it does not already
	 * exist, or returns the existing one unchanged — structurally
	 * exactly-once via summary_date's own UNIQUE constraint, not an
	 * application-level lock (M11B plan §2.1/§4).
	 *
	 * @param string $summary_date      The UTC calendar day, 'Y-m-d'.
	 * @param string $window_started_at The window's own start timestamp.
	 * @param string $window_ended_at   The window's own end timestamp.
	 *
	 * @return array<string, mixed>|null The row, or null if the schema is unavailable.
	 */
	public function create_or_get_for_date( string $summary_date, string $window_started_at, string $window_ended_at ): ?array {
		if ( ! $this->schema_health->is_available() ) {
			return null;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::OPERATIONAL_SUMMARY_RUNS_TABLE;
		$now   = current_time( 'mysql', true );

		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"INSERT INTO {$table} (summary_date, window_started_at, window_ended_at, created_at)
					VALUES (%s, %s, %s, %s)
					ON DUPLICATE KEY UPDATE summary_date = summary_date",
				$summary_date,
				$window_started_at,
				$window_ended_at,
				$now
			)
		);

		return $this->find_by_date( $summary_date );
	}

	/**
	 * The row for the given UTC calendar day, if any.
	 *
	 * @param string $summary_date The UTC calendar day, 'Y-m-d'.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find_by_date( string $summary_date ): ?array {
		if ( ! $this->schema_health->is_available() ) {
			return null;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::OPERATIONAL_SUMMARY_RUNS_TABLE;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE summary_date = %s",
				$summary_date
			),
			ARRAY_A
		);

		return null === $row ? null : $row;
	}

	/**
	 * The row for the given primary key, if any.
	 *
	 * @param int $id The row's own id.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		if ( ! $this->schema_health->is_available() ) {
			return null;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::OPERATIONAL_SUMMARY_RUNS_TABLE;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE id = %d",
				$id
			),
			ARRAY_A
		);

		return null === $row ? null : $row;
	}

	/**
	 * The most recently created row, if any — the source for the
	 * operator-triggered AI summary (§2.6), never a live query.
	 *
	 * @return array<string, mixed>|null
	 */
	public function most_recent(): ?array {
		if ( ! $this->schema_health->is_available() ) {
			return null;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::OPERATIONAL_SUMMARY_RUNS_TABLE;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( "SELECT * FROM {$table} ORDER BY id DESC LIMIT 1", ARRAY_A );

		return null === $row ? null : $row;
	}

	/**
	 * Overwrites every aggregate count column on the given row.
	 *
	 * @param int                  $id     The row's own id.
	 * @param array<string, int>   $counts Column-name => integer-count pairs; only recognized columns are written.
	 * @param bool                 $woocommerce_active Recorded as of this computation.
	 */
	public function save_counts( int $id, array $counts, bool $woocommerce_active ): void {
		if ( ! $this->schema_health->is_available() ) {
			return;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::OPERATIONAL_SUMMARY_RUNS_TABLE;

		$allowed = array(
			'orders_created',
			'payments_completed',
			'orders_failed',
			'orders_cancelled',
			'checkout_failures',
			'js_error_runtime',
			'js_error_promise',
			'js_error_resource',
			'funnel_product_views',
			'funnel_cart_intents',
			'funnel_checkout_starts',
			'funnel_orders_created',
		);

		$data = array( 'woocommerce_active_at_run' => $woocommerce_active ? 1 : 0 );

		foreach ( $counts as $column => $value ) {
			if ( in_array( $column, $allowed, true ) ) {
				$data[ $column ] = (int) $value;
			}
		}

		$wpdb->update( $table, $data, array( 'id' => $id ) );
	}

	/**
	 * Records a successful send.
	 *
	 * @param int    $id      The row's own id.
	 * @param string $sent_at The send timestamp.
	 */
	public function mark_sent( int $id, string $sent_at ): void {
		if ( ! $this->schema_health->is_available() ) {
			return;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::OPERATIONAL_SUMMARY_RUNS_TABLE;

		$wpdb->update( $table, array( 'sent_at' => $sent_at, 'send_status' => 'sent' ), array( 'id' => $id ) );
	}

	/**
	 * Records a failed or skipped send attempt without touching sent_at —
	 * the next sweep tick retries against the same row (no data loss).
	 *
	 * @param int    $id     The row's own id.
	 * @param string $status 'send_failed' or 'skipped_invalid_target'.
	 */
	public function mark_send_status( int $id, string $status ): void {
		if ( ! $this->schema_health->is_available() ) {
			return;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::OPERATIONAL_SUMMARY_RUNS_TABLE;

		$wpdb->update( $table, array( 'send_status' => $status ), array( 'id' => $id ) );
	}

	/**
	 * A bounded COUNT against event_history for one event type since a
	 * given timestamp — the same query shape M11A's own counter-sum
	 * pattern already uses; never an unbounded scan.
	 *
	 * @param string $event_type The event type.
	 * @param string $since      The inclusive lower bound, 'Y-m-d H:i:s' UTC.
	 *
	 * @return int
	 */
	public function count_event_type_since( string $event_type, string $since ): int {
		if ( ! $this->schema_health->is_available() ) {
			return 0;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::EVENT_HISTORY_TABLE;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM {$table} WHERE event_type = %s AND occurred_at >= %s",
				$event_type,
				$since
			)
		);
	}
}
