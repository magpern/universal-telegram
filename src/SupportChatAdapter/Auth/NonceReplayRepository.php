<?php
/**
 * Nonce replay store (ADR-0007 §3).
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\SupportChatAdapter\Auth;

use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Persistence\SchemaHealth;

/**
 * Holds only (sender, key_id, nonce, recorded_at) — never a request body or
 * any Contract payload field (ADR-0007 §3, §5). Retention is a 600-second
 * window (acceptance window plus clock-skew margin); expired rows are
 * purged by routine housekeeping (NonceReplaySweep), not read as part of
 * replay checks.
 */
class NonceReplayRepository {

	public const RETENTION_SECONDS = 600;

	/**
	 * Constructor.
	 *
	 * @param SchemaHealth $schema_health Schema availability gate.
	 */
	public function __construct( private readonly SchemaHealth $schema_health ) {}

	/**
	 * Atomically records a nonce for a sender/key. Returns true when this
	 * is the first time the tuple has been seen (accept), false when it is
	 * a replay (reject) or the schema is unavailable (reject).
	 *
	 * The database's own UNIQUE KEY is the race-free guard: two concurrent
	 * requests for the same tuple can only ever have one INSERT succeed.
	 *
	 * @param string $sender Sender plugin slug.
	 * @param string $key_id Sender key ID.
	 * @param string $nonce  Per-request nonce.
	 */
	public function record_if_new( string $sender, string $key_id, string $nonce ): bool {
		if ( ! $this->schema_health->is_available() ) {
			return false;
		}

		global $wpdb;

		$table    = $wpdb->prefix . Migrator::CONTRACT_NONCES_TABLE;
		$inserted = $wpdb->insert(
			$table,
			array(
				'sender'      => $sender,
				'key_id'      => $key_id,
				'nonce'       => $nonce,
				'recorded_at' => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s' )
		);

		return false !== $inserted;
	}

	/**
	 * Purges nonce records older than the retention window. Safe to call
	 * repeatedly; never removes a tuple still inside the window.
	 *
	 * @return int Rows removed.
	 */
	public function purge_expired(): int {
		if ( ! $this->schema_health->is_available() ) {
			return 0;
		}

		global $wpdb;

		$table  = $wpdb->prefix . Migrator::CONTRACT_NONCES_TABLE;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::RETENTION_SECONDS );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name.
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE recorded_at < %s",
				$cutoff
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return false === $deleted ? 0 : (int) $deleted;
	}
}
