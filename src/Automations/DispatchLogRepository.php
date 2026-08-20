<?php
/**
 * Idempotent notification dispatch-log persistence.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Automations;

use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Persistence\SchemaHealth;

/**
 * The UNIQUE(rule_id, event_id) constraint on notification_dispatch_log is
 * the sole duplicate-prevention mechanism: claim_or_reject()'s atomic
 * INSERT IGNORE either creates the one and only row for a (rule_id,
 * event_id) pair, or — if a row already exists — returns
 * DispatchLogResult::SKIPPED_DUPLICATE and performs no further write of any
 * kind (M02 plan §7.5, docs/adr/0016).
 */
final class DispatchLogRepository {

	/**
	 * Constructor.
	 *
	 * @param SchemaHealth $schema_health Checked before every operation.
	 */
	public function __construct( private readonly SchemaHealth $schema_health ) {}

	/**
	 * Atomically claims or rejects one (rule_id, event_id) pair. Returns
	 * null only if the schema is unavailable (no write attempted).
	 *
	 * @param int                $rule_id        The matched (or rejected) rule's primary key.
	 * @param string             $event_id       The event's deterministic identity.
	 * @param DispatchLogResult  $initial_result CLAIMED (a rule matched) or REJECTED (it did not).
	 *
	 * @return DispatchLogResult|null
	 */
	public function claim_or_reject( int $rule_id, string $event_id, DispatchLogResult $initial_result ): ?DispatchLogResult {
		if ( ! $this->schema_health->is_available() ) {
			return null;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::DISPATCH_LOG_TABLE;
		$now   = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name, never user input.
		$affected = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (rule_id, event_id, result, dispatched_at, updated_at) VALUES (%d, %s, %s, %s, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$rule_id,
				$event_id,
				$initial_result->value,
				$now,
				$now
			)
		);

		if ( 1 !== (int) $affected ) {
			return DispatchLogResult::SKIPPED_DUPLICATE;
		}

		return $initial_result;
	}

	/**
	 * Convenience wrapper: records a rejected outcome for a (rule_id,
	 * event_id) pair that never reached the claimed state.
	 *
	 * @param int    $rule_id  The rejected rule's primary key.
	 * @param string $event_id The event's deterministic identity.
	 */
	public function record_rejected( int $rule_id, string $event_id ): void {
		$this->claim_or_reject( $rule_id, $event_id, DispatchLogResult::REJECTED );
	}

	/**
	 * Updates a previously claimed row to its terminal state.
	 *
	 * @param int                $rule_id                The rule's primary key.
	 * @param string             $event_id               The event's deterministic identity.
	 * @param DispatchLogResult  $result                 The terminal result.
	 * @param string|null        $outbound_message_uuid   Set only on a successful handoff, when known.
	 * @param string|null        $reason_code             Set only on a skip/failure outcome.
	 */
	public function update(
		int $rule_id,
		string $event_id,
		DispatchLogResult $result,
		?string $outbound_message_uuid = null,
		?string $reason_code = null
	): void {
		if ( ! $this->schema_health->is_available() ) {
			return;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::DISPATCH_LOG_TABLE;

		$wpdb->update(
			$table,
			array(
				'result'                 => $result->value,
				'outbound_message_uuid'  => $outbound_message_uuid,
				'reason_code'            => $reason_code,
				'updated_at'             => current_time( 'mysql', true ),
			),
			array(
				'rule_id'  => $rule_id,
				'event_id' => $event_id,
			),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d', '%s' )
		);
	}

	/**
	 * The most recent HANDED_OFF_TO_M01 row's own updated_at timestamp for
	 * a rule, used exclusively for cooldown timing — never a CLAIMED row,
	 * to avoid an in-flight claim confusing cooldown timing (M02 plan §7.5).
	 *
	 * @param int $rule_id The rule's primary key.
	 *
	 * @return string|null MySQL DATETIME string, or null if none exists.
	 */
	public function most_recent_handoff_at( int $rule_id ): ?string {
		if ( ! $this->schema_health->is_available() ) {
			return null;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::DISPATCH_LOG_TABLE;
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MAX(updated_at) FROM {$table} WHERE rule_id = %d AND result = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$rule_id,
				DispatchLogResult::HANDED_OFF_TO_M01->value
			)
		);

		return is_string( $value ) ? $value : null;
	}

	/**
	 * Counts FAILED_BEFORE_HANDOFF rows dispatched within the last 24
	 * hours. Used only for diagnostics aggregation.
	 *
	 * @return int
	 */
	public function failed_count_24h(): int {
		if ( ! $this->schema_health->is_available() ) {
			return 0;
		}

		global $wpdb;

		$table     = $wpdb->prefix . Migrator::DISPATCH_LOG_TABLE;
		$threshold = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE result = %s AND dispatched_at >= %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				DispatchLogResult::FAILED_BEFORE_HANDOFF->value,
				$threshold
			)
		);
	}

	/**
	 * Counts CLAIMED rows older than a staleness threshold — the
	 * diagnosable, never-hidden signal for the one deliberately accepted,
	 * non-retried limitation in this design (M02 plan §7.5).
	 *
	 * @param int $threshold_minutes The staleness threshold, in minutes.
	 *
	 * @return int
	 */
	public function stuck_claim_count( int $threshold_minutes = 30 ): int {
		if ( ! $this->schema_health->is_available() ) {
			return 0;
		}

		global $wpdb;

		$table     = $wpdb->prefix . Migrator::DISPATCH_LOG_TABLE;
		$threshold = gmdate( 'Y-m-d H:i:s', time() - ( $threshold_minutes * MINUTE_IN_SECONDS ) );

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE result = %s AND dispatched_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				DispatchLogResult::CLAIMED->value,
				$threshold
			)
		);
	}
}
