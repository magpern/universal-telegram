<?php
/**
 * Deferred webhook update persistence.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Migration;

use UniversalTelegram\Core\Security\CredentialState;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Persistence\SchemaHealth;

/**
 * CRUD for Table 3, `{$wpdb->prefix}universal_telegram_quiescence_deferred_updates`
 * (docs/adr/0040 §3–§4): encrypted-at-rest buffering of Telegram webhook
 * updates arriving while the plugin is not `idle`, and their later,
 * ordered replay. `payload_ciphertext` is written and read only by this
 * class; every other accessor (`status`, CLI, diagnostics, audit) is
 * confined to `DeferredUpdateRecord`, which never carries it.
 */
class DeferredUpdateRepository {

	/**
	 * Constructor.
	 *
	 * @param SchemaHealth    $schema_health Checked before every operation.
	 * @param CredentialVault $vault         Encrypts/decrypts buffered payloads.
	 */
	public function __construct(
		private readonly SchemaHealth $schema_health,
		private readonly CredentialVault $vault
	) {}

	/**
	 * The encryption/decryption additional-authenticated-data context for a
	 * given (bot_id, update_id) — mirrors
	 * `Conversations\MessageRepository::context()`'s per-item binding
	 * exactly (docs/adr/0040 §3): a ciphertext row can only ever be
	 * decrypted against its own (bot_id, update_id).
	 *
	 * @param int $bot_id    The receiving bot.
	 * @param int $update_id Telegram's own update_id.
	 *
	 * @return string
	 */
	private function context( int $bot_id, int $update_id ): string {
		return "quiescence-deferred-update:{$bot_id}:{$update_id}";
	}

	/**
	 * Buffers one raw update payload, encrypted at rest. Idempotent: if
	 * `(bot_id, update_id)` already exists (its UNIQUE KEY), this is a
	 * silent no-op — the resulting duplicate-key condition is never
	 * surfaced as an error, and no second row or second encryption attempt
	 * occurs (docs/adr/0040 §3).
	 *
	 * @param int                  $bot_id      The receiving bot.
	 * @param int                  $update_id   Telegram's own update_id.
	 * @param string               $update_type The update's type (metadata only).
	 * @param array<string, mixed> $raw_payload The full decoded update body.
	 *
	 * @return bool True if the row is durably buffered (either just now, or
	 *              already, by an earlier delivery attempt); false only if
	 *              the schema is unavailable or encryption itself failed.
	 */
	public function buffer( int $bot_id, int $update_id, string $update_type, array $raw_payload ): bool {
		if ( ! $this->schema_health->is_available() ) {
			return false;
		}

		if ( $this->exists( $bot_id, $update_id ) ) {
			return true;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::QUIESCENCE_DEFERRED_UPDATES_TABLE;

		$encoded = wp_json_encode( $raw_payload );

		if ( false === $encoded ) {
			return false;
		}

		try {
			$ciphertext = $this->vault->encrypt( $encoded, $this->context( $bot_id, $update_id ) );
		} catch ( \Throwable $exception ) {
			return false;
		}

		// INSERT IGNORE: a concurrent buffer attempt for the identical
		// (bot_id, update_id) — Telegram's own at-least-once redelivery —
		// loses the UNIQUE KEY race silently rather than raising an error;
		// this is the intended idempotent outcome, not a failure.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (bot_id, update_id, update_type, payload_ciphertext, received_at) VALUES (%d, %d, %s, %s, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$bot_id,
				$update_id,
				$update_type,
				$ciphertext,
				current_time( 'mysql', true )
			)
		);

		return true;
	}

	/**
	 * Whether a (bot_id, update_id) row already exists.
	 *
	 * @param int $bot_id    The receiving bot.
	 * @param int $update_id Telegram's own update_id.
	 *
	 * @return bool
	 */
	public function exists( int $bot_id, int $update_id ): bool {
		global $wpdb;

		$table = $wpdb->prefix . Migrator::QUIESCENCE_DEFERRED_UPDATES_TABLE;

		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE bot_id = %d AND update_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$bot_id,
				$update_id
			)
		);

		return null !== $found && (int) $found > 0;
	}

	/**
	 * The current unreplayed backlog count. Never touches
	 * `payload_ciphertext` (docs/adr/0040 §8).
	 *
	 * @return int
	 */
	public function backlog_count(): int {
		if ( ! $this->schema_health->is_available() ) {
			return 0;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::QUIESCENCE_DEFERRED_UPDATES_TABLE;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE replayed_at IS NULL" );

		return null === $count ? 0 : (int) $count;
	}

	/**
	 * The age, in seconds, of the single oldest unreplayed row. Null if no
	 * row is currently unreplayed. Used by `status`'s 24-hour health flag
	 * (docs/adr/0040 §3) — never touches `payload_ciphertext`.
	 *
	 * @return int|null
	 */
	public function oldest_unreplayed_age_seconds(): ?int {
		global $wpdb;

		$table = $wpdb->prefix . Migrator::QUIESCENCE_DEFERRED_UPDATES_TABLE;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$oldest = $wpdb->get_var( "SELECT MIN(received_at) FROM {$table} WHERE replayed_at IS NULL" );

		if ( null === $oldest || '' === $oldest ) {
			return null;
		}

		$oldest_timestamp = strtotime( $oldest . ' UTC' );

		if ( false === $oldest_timestamp ) {
			return null;
		}

		return max( 0, time() - $oldest_timestamp );
	}

	/**
	 * Every currently-unreplayed row, in deterministic per-bot replay order
	 * (docs/adr/0040 §3): grouped by bot_id, ordered by update_id ascending
	 * within each bot, with the table's own auto-increment id as a stable
	 * tie-breaker.
	 *
	 * @return array<int, array<int, DeferredUpdateRecord>> Keyed by bot_id.
	 */
	public function unreplayed_grouped_by_bot(): array {
		global $wpdb;

		$table = $wpdb->prefix . Migrator::QUIESCENCE_DEFERRED_UPDATES_TABLE;

		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT id, bot_id, update_id, update_type, received_at, replayed_at FROM {$table} WHERE replayed_at IS NULL ORDER BY bot_id ASC, update_id ASC, id ASC",
			ARRAY_A
		);

		$grouped = array();
		$rows    = is_array( $rows ) ? $rows : array();

		foreach ( $rows as $row ) {
			$bot_id = (int) $row['bot_id'];

			if ( ! isset( $grouped[ $bot_id ] ) ) {
				$grouped[ $bot_id ] = array();
			}

			$grouped[ $bot_id ][] = $this->hydrate( $row );
		}

		return $grouped;
	}

	/**
	 * Decrypts one row's raw update payload. Called only inside the replay
	 * path, at the point the plaintext is about to be handed to
	 * `WebhookController::process_update()`; the decrypted value is never
	 * itself logged or persisted a second time (docs/adr/0040 §3).
	 *
	 * @param DeferredUpdateRecord $record The row to decrypt.
	 *
	 * @return array<string, mixed>|null Null if decryption fails for any
	 *                                    reason (unavailable key, corrupted
	 *                                    ciphertext, malformed JSON).
	 */
	public function decrypt_payload( DeferredUpdateRecord $record ): ?array {
		global $wpdb;

		$table = $wpdb->prefix . Migrator::QUIESCENCE_DEFERRED_UPDATES_TABLE;

		$stored = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT payload_ciphertext FROM {$table} WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$record->id()
			)
		);

		if ( null === $stored ) {
			return null;
		}

		$result = $this->vault->decrypt( $stored, $this->context( $record->bot_id(), $record->update_id() ) );

		if ( CredentialState::AVAILABLE !== $result->state() || null === $result->plaintext() ) {
			return null;
		}

		$decoded = json_decode( $result->plaintext(), true );

		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Stamps `replayed_at`, only ever called after
	 * `WebhookController::process_update()` has already returned
	 * successfully for this row (docs/adr/0040 §3).
	 *
	 * @param int $id The row's own primary key.
	 */
	public function mark_replayed( int $id ): void {
		global $wpdb;

		$table = $wpdb->prefix . Migrator::QUIESCENCE_DEFERRED_UPDATES_TABLE;

		$wpdb->update(
			$table,
			array( 'replayed_at' => current_time( 'mysql', true ) ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Permanently deletes replayed rows older than the given retention
	 * window (docs/adr/0040 §3: 30 days). Never touches an unreplayed row
	 * (`replayed_at IS NULL`) — auto-deleting one would be data loss.
	 *
	 * @param int $older_than_days The retention window, in days.
	 *
	 * @return int The number of rows deleted.
	 */
	public function delete_replayed_older_than( int $older_than_days ): int {
		if ( ! $this->schema_health->is_available() ) {
			return 0;
		}

		global $wpdb;

		$table  = $wpdb->prefix . Migrator::QUIESCENCE_DEFERRED_UPDATES_TABLE;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $older_than_days * DAY_IN_SECONDS ) );

		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE replayed_at IS NOT NULL AND replayed_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$cutoff
			)
		);

		return false === $deleted ? 0 : (int) $deleted;
	}

	/**
	 * Hydrates one database row into a value object.
	 *
	 * @param array<string, mixed> $row The raw database row.
	 *
	 * @return DeferredUpdateRecord
	 */
	private function hydrate( array $row ): DeferredUpdateRecord {
		return new DeferredUpdateRecord(
			(int) $row['id'],
			(int) $row['bot_id'],
			(int) $row['update_id'],
			(string) $row['update_type'],
			(string) $row['received_at'],
			null === $row['replayed_at'] ? null : (string) $row['replayed_at']
		);
	}
}
