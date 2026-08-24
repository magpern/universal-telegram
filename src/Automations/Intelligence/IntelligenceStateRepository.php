<?php
/**
 * Intelligence sweep singleton claim-lease mutex persistence.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Automations\Intelligence;

use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Persistence\SchemaHealth;

/**
 * A single seeded row (id=1) in universal_telegram_intelligence_settings_state
 * (M11B plan §4, step 26) — the same "singleton row as mutex/checkpoint"
 * pattern Automations\Digest\VisitorDigestStateRepository already
 * establishes for the visitor digest, scoped here purely to the send-handoff
 * claim/lease step; the operational summary's own "was it sent" record
 * lives on operational_summary_runs.sent_at/send_status (§4), never
 * duplicated on this row.
 */
final class IntelligenceStateRepository {

	private const ROW_ID = 1;

	/**
	 * Constructor.
	 *
	 * @param SchemaHealth $schema_health Checked before every operation.
	 */
	public function __construct( private readonly SchemaHealth $schema_health ) {}

	/**
	 * Attempts to atomically claim the mutex for a send: succeeds only if no
	 * claim is currently held, or a previously held claim's lease has
	 * expired. A compare-and-set UPDATE, not a held transaction, since a
	 * real lock would otherwise need to be held across the Telegram HTTP
	 * call itself (mirrors VisitorDigestStateRepository::try_claim_for_send()).
	 *
	 * @param string $claim_token      A fresh, opaque claim identifier.
	 * @param string $claim_expires_at The lease expiry timestamp.
	 *
	 * @return bool Whether this call won the claim.
	 */
	public function try_claim( string $claim_token, string $claim_expires_at ): bool {
		if ( ! $this->schema_health->is_available() ) {
			return false;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::INTELLIGENCE_SETTINGS_STATE_TABLE;
		$now   = current_time( 'mysql', true );

		$updated = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE {$table} SET claim_token = %s, claim_expires_at = %s
					WHERE id = %d AND (claim_token IS NULL OR claim_expires_at < %s)",
				$claim_token,
				$claim_expires_at,
				self::ROW_ID,
				$now
			)
		);

		return is_int( $updated ) && $updated > 0;
	}

	/**
	 * Releases this call's own claim, scoped to its exact claim_token so a
	 * stale, delayed caller can never clear a newer claim's own hold.
	 *
	 * @param string $claim_token This call's own claim token.
	 */
	public function release( string $claim_token ): void {
		if ( ! $this->schema_health->is_available() ) {
			return;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::INTELLIGENCE_SETTINGS_STATE_TABLE;

		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE {$table} SET claim_token = NULL, claim_expires_at = NULL WHERE id = %d AND claim_token = %s",
				self::ROW_ID,
				$claim_token
			)
		);
	}
}
