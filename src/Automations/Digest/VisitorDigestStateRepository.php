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
}
