<?php
/**
 * Visitor digest singleton state/checkpoint row persistence.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Automations\Digest;

use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Persistence\SchemaHealth;

/**
 * A single seeded row (id=1) — the same "singleton row as mutex/checkpoint"
 * pattern ADR-0028 established for universal_telegram_ai_config
 * (docs/plans/m11a-visitor-activity-digests-plan-v1.md §5).
 */
final class VisitorDigestStateRepository {

	private const ROW_ID = 1;

	/**
	 * Constructor.
	 *
	 * @param SchemaHealth $schema_health Checked before every operation.
	 */
	public function __construct( private readonly SchemaHealth $schema_health ) {}

	/**
	 * The currently open window's own timestamp, or null if none is open.
	 *
	 * @return string|null
	 */
	public function current_window_started_at(): ?string {
		if ( ! $this->schema_health->is_available() ) {
			return null;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::VISITOR_DIGEST_STATE_TABLE;

		$value = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT window_started_at FROM {$table} WHERE id = %d",
				self::ROW_ID
			)
		);

		return null === $value ? null : (string) $value;
	}

	/**
	 * Opens a new window at the given timestamp if, and only if, none is
	 * currently open — an atomic, race-safe conditional UPDATE: two
	 * concurrent requests racing here both issue the same statement, but
	 * only the first actually matches the `window_started_at IS NULL`
	 * clause and changes the row; the second's WHERE clause no longer
	 * matches and it is a safe no-op. Either way, the caller re-reads the
	 * now-authoritative value via current_window_started_at() rather than
	 * trusting its own $now — the same "open" timestamp is what every
	 * concurrent caller observes.
	 *
	 * @param string $now The candidate window-open timestamp.
	 *
	 * @return string The window's own timestamp, whether just opened by this call or already open.
	 */
	public function open_window_if_needed( string $now ): string {
		if ( ! $this->schema_health->is_available() ) {
			return $now;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::VISITOR_DIGEST_STATE_TABLE;

		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE {$table} SET window_started_at = %s WHERE id = %d AND window_started_at IS NULL",
				$now,
				self::ROW_ID
			)
		);

		return $this->current_window_started_at() ?? $now;
	}

	/**
	 * Attempts to atomically claim the given window for a send: succeeds
	 * only if the row's own window_started_at still matches (no other
	 * process already closed/reopened it) AND no other claim is currently
	 * held (claim_token IS NULL) or the previously held claim's own lease
	 * has expired. This single conditional UPDATE is the sole admission
	 * mutex (docs/plans/m11a-visitor-activity-digests-plan-v1.md §5) —
	 * deliberately a compare-and-set rather than a held SELECT ... FOR
	 * UPDATE transaction, since a real lock would otherwise need to be held
	 * across the Telegram HTTP call itself.
	 *
	 * @param string $window_started_at The window being claimed.
	 * @param string $claim_token       A fresh, opaque claim identifier.
	 * @param string $claim_expires_at  The lease expiry timestamp.
	 *
	 * @return bool Whether this call won the claim.
	 */
	public function try_claim_for_send( string $window_started_at, string $claim_token, string $claim_expires_at ): bool {
		if ( ! $this->schema_health->is_available() ) {
			return false;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::VISITOR_DIGEST_STATE_TABLE;
		$now   = current_time( 'mysql', true );

		$updated = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE {$table} SET claim_token = %s, claim_expires_at = %s
					WHERE id = %d AND window_started_at = %s AND (claim_token IS NULL OR claim_expires_at < %s)",
				$claim_token,
				$claim_expires_at,
				self::ROW_ID,
				$window_started_at,
				$now
			)
		);

		return is_int( $updated ) && $updated > 0;
	}

	/**
	 * Closes the window after a successful send: clears window_started_at
	 * and the claim fields, records the send timestamp/status. Scoped to
	 * the exact (window_started_at, claim_token) pair this call itself
	 * claimed, so a stale, delayed caller can never overwrite a newer
	 * claim's own outcome.
	 *
	 * @param string $window_started_at The window that was sent.
	 * @param string $claim_token       This call's own claim token.
	 * @param string $sent_at           The send timestamp.
	 *
	 * @return bool Whether the close actually applied.
	 */
	public function close_window_after_send( string $window_started_at, string $claim_token, string $sent_at ): bool {
		if ( ! $this->schema_health->is_available() ) {
			return false;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::VISITOR_DIGEST_STATE_TABLE;

		$updated = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE {$table} SET window_started_at = NULL, claim_token = NULL, claim_expires_at = NULL,
					last_digest_sent_at = %s, last_digest_status = 'sent'
					WHERE id = %d AND window_started_at = %s AND claim_token = %s",
				$sent_at,
				self::ROW_ID,
				$window_started_at,
				$claim_token
			)
		);

		return is_int( $updated ) && $updated > 0;
	}

	/**
	 * Releases a claim after a failed send attempt: clears only the claim
	 * fields and records the failure status, leaving window_started_at
	 * untouched so the next sweep tick retries against the same window —
	 * no data loss (docs/plans/m11a-visitor-activity-digests-plan-v1.md §5,
	 * "Duplicate/crash safety").
	 *
	 * @param string $claim_token This call's own claim token.
	 * @param string $status      The recorded failure status, e.g. 'send_failed'.
	 */
	public function release_claim_after_failure( string $claim_token, string $status ): void {
		if ( ! $this->schema_health->is_available() ) {
			return;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::VISITOR_DIGEST_STATE_TABLE;

		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE {$table} SET claim_token = NULL, claim_expires_at = NULL, last_digest_status = %s
					WHERE id = %d AND claim_token = %s",
				$status,
				self::ROW_ID,
				$claim_token
			)
		);
	}

	/**
	 * Records a skip outcome (invalid target, or no eligible events) without
	 * touching window_started_at or any claim field — used by the sweep
	 * when it declines to evaluate or send, purely for diagnostics
	 * visibility (§7).
	 *
	 * @param string $status The recorded status, e.g. 'skipped_invalid_target'.
	 */
	public function record_skip( string $status ): void {
		if ( ! $this->schema_health->is_available() ) {
			return;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::VISITOR_DIGEST_STATE_TABLE;

		$wpdb->update( $table, array( 'last_digest_status' => $status ), array( 'id' => self::ROW_ID ) );
	}

	/**
	 * The most recent digest-send timestamp, or null if none has ever sent.
	 *
	 * @return string|null
	 */
	public function last_digest_sent_at(): ?string {
		return $this->scalar_column( 'last_digest_sent_at' );
	}

	/**
	 * The most recent recorded status (sent|send_failed|skipped_invalid_target|
	 * skipped_no_events), or null if the sweep has never run.
	 *
	 * @return string|null
	 */
	public function last_digest_status(): ?string {
		return $this->scalar_column( 'last_digest_status' );
	}

	/**
	 * Reads one nullable scalar column from the singleton row.
	 *
	 * @param string $column The column name — always a fixed, internal string, never user input.
	 *
	 * @return string|null
	 */
	private function scalar_column( string $column ): ?string {
		if ( ! $this->schema_health->is_available() ) {
			return null;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::VISITOR_DIGEST_STATE_TABLE;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $column is one of two fixed internal literals, never user input.
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT {$column} FROM {$table} WHERE id = %d", self::ROW_ID ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return null === $value ? null : (string) $value;
	}
}
