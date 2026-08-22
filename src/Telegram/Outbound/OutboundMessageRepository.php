<?php
/**
 * Outbound message persistence.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Telegram\Outbound;

use UniversalTelegram\Core\Security\CredentialResult;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Persistence\SchemaHealth;

/**
 * CredentialVault-backed CRUD for outbound messages. Message content is
 * written here, encrypted, before any Queue\JobEnvelope is ever constructed
 * (docs/adr/0012) — the queue payload carries only this row's opaque
 * message_uuid.
 */
final class OutboundMessageRepository {

	private const CONTEXT_PREFIX = 'telegram.outbound_message:';

	/**
	 * Constructor.
	 *
	 * @param SchemaHealth    $schema_health    Checked before every operation.
	 * @param CredentialVault $credential_vault Encrypts/decrypts message content.
	 */
	public function __construct(
		private readonly SchemaHealth $schema_health,
		private readonly CredentialVault $credential_vault
	) {}

	/**
	 * Creates a new outbound message, encrypted at rest.
	 *
	 * @param int         $bot_id          The owning bot's primary key.
	 * @param int         $destination_id  The target destination's primary key.
	 * @param string      $body_plaintext  The message text.
	 * @param string|null $parse_mode      Telegram's own parse_mode parameter.
	 *
	 * @return OutboundMessage|null Null if the schema is unavailable or the insert failed.
	 */
	public function create( int $bot_id, int $destination_id, string $body_plaintext, ?string $parse_mode ): ?OutboundMessage {
		if ( ! $this->schema_health->is_available() ) {
			return null;
		}

		global $wpdb;

		$message_uuid = wp_generate_uuid4();
		$now          = current_time( 'mysql', true );

		$table    = $wpdb->prefix . Migrator::OUTBOUND_MESSAGES_TABLE;
		$inserted = $wpdb->insert(
			$table,
			array(
				'message_uuid'    => $message_uuid,
				'bot_id'          => $bot_id,
				'destination_id'  => $destination_id,
				'body_ciphertext' => $this->credential_vault->encrypt( $body_plaintext, self::CONTEXT_PREFIX . $message_uuid ),
				'parse_mode'      => $parse_mode,
				'status'          => OutboundMessageStatus::PENDING->value,
				'created_at'      => $now,
				'updated_at'      => $now,
			),
			array( '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return null;
		}

		return $this->find( (int) $wpdb->insert_id );
	}

	/**
	 * Finds a message by primary key.
	 *
	 * @param int $id The message's primary key.
	 *
	 * @return OutboundMessage|null
	 */
	public function find( int $id ): ?OutboundMessage {
		if ( ! $this->schema_health->is_available() ) {
			return null;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::OUTBOUND_MESSAGES_TABLE;
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return null === $row ? null : $this->hydrate( $row );
	}

	/**
	 * Finds a message by its opaque UUID — the only lookup the queue's own
	 * handler ever performs.
	 *
	 * @param string $message_uuid The message's opaque UUID.
	 *
	 * @return OutboundMessage|null
	 */
	public function find_by_uuid( string $message_uuid ): ?OutboundMessage {
		if ( ! $this->schema_health->is_available() ) {
			return null;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::OUTBOUND_MESSAGES_TABLE;
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE message_uuid = %s", $message_uuid ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return null === $row ? null : $this->hydrate( $row );
	}

	/**
	 * Decrypts a message's own body.
	 *
	 * @param OutboundMessage $message The message.
	 *
	 * @return CredentialResult|null Null if the body has already been purged.
	 */
	public function decrypt_body( OutboundMessage $message ): ?CredentialResult {
		if ( null === $message->body_ciphertext() ) {
			return null;
		}

		return $this->credential_vault->decrypt( $message->body_ciphertext(), self::CONTEXT_PREFIX . $message->message_uuid() );
	}

	/**
	 * Marks a message as currently being sent, incrementing its attempt count.
	 *
	 * @param int $id The message's primary key.
	 *
	 * @return bool
	 */
	public function mark_sending( int $id ): bool {
		$message = $this->find( $id );

		if ( null === $message ) {
			return false;
		}

		return $this->update(
			$id,
			array(
				'status'        => OutboundMessageStatus::SENDING->value,
				'attempt_count' => $message->attempt_count() + 1,
			)
		);
	}

	/**
	 * Atomically claims a message for sending under a persisted, time-bound
	 * lease: the sole caller whose own `UPDATE` actually matches a row may
	 * proceed to call `TelegramApiClient::send_message()` for it. Matches
	 * rows in `pending`/`retry_scheduled` (any caller may claim a fresh or
	 * previously-deferred attempt) or `sending` with an already-expired
	 * lease (a prior claimant crashed without releasing it). Never matches
	 * a terminal status (`sent`/`dead_letter`/`purged`) — once reached, no
	 * claim can ever be re-acquired, which is the actual double-send
	 * prevention (M06.2 corrective plan v2 §3.1, ADR-0023 amendment).
	 *
	 * Uses a literal raw-SQL `UPDATE ... WHERE ... OR (...)` rather than
	 * `$wpdb->update()`'s compound-WHERE helper, because that helper can
	 * only AND its WHERE columns together — the reclaim-on-expiry branch
	 * genuinely requires an OR.
	 *
	 * @param int $id            The message's primary key.
	 * @param int $lease_seconds How long this caller's claim remains valid.
	 *
	 * @return bool True only if this call won the claim.
	 */
	public function try_claim_for_sending( int $id, int $lease_seconds = 15 ): bool {
		if ( ! $this->schema_health->is_available() ) {
			return false;
		}

		global $wpdb;

		$table        = $wpdb->prefix . Migrator::OUTBOUND_MESSAGES_TABLE;
		$now          = current_time( 'mysql', true );
		$lease_expiry = gmdate( 'Y-m-d H:i:s', strtotime( $now ) + $lease_seconds );

		$updated = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE {$table}
					SET status = %s, claim_expires_at = %s, attempt_count = attempt_count + 1, updated_at = %s
					WHERE id = %d
					  AND ( status IN ( %s, %s )
					        OR ( status = %s AND claim_expires_at IS NOT NULL AND claim_expires_at < %s ) )",
				OutboundMessageStatus::SENDING->value,
				$lease_expiry,
				$now,
				$id,
				OutboundMessageStatus::PENDING->value,
				OutboundMessageStatus::RETRY_SCHEDULED->value,
				OutboundMessageStatus::SENDING->value,
				$now
			)
		);

		return false !== $updated && $updated > 0;
	}

	/**
	 * Gracefully releases a held claim without waiting out its lease,
	 * reverting the row to a re-claimable status so the next attempt (an
	 * in-process fallback or the durable queue) may reclaim immediately
	 * (M06.2 corrective plan v2 §3.1).
	 *
	 * @param int $id The message's primary key.
	 *
	 * @return bool
	 */
	public function release_claim_for_retry( int $id ): bool {
		return $this->update(
			$id,
			array(
				'status'           => OutboundMessageStatus::RETRY_SCHEDULED->value,
				'claim_expires_at' => null,
			)
		);
	}

	/**
	 * Marks a message sent.
	 *
	 * @param int      $id                   The message's primary key.
	 * @param int|null $telegram_message_id Telegram's own returned message ID.
	 *
	 * @return bool
	 */
	public function mark_sent( int $id, ?int $telegram_message_id ): bool {
		return $this->update(
			$id,
			array(
				'status'              => OutboundMessageStatus::SENT->value,
				'telegram_message_id' => $telegram_message_id,
				'sent_at'             => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * Marks a message scheduled for a generic retry.
	 *
	 * @param int $id The message's primary key.
	 *
	 * @return bool
	 */
	public function mark_retry_scheduled( int $id ): bool {
		return $this->update(
			$id,
			array(
				'status'           => OutboundMessageStatus::RETRY_SCHEDULED->value,
				'claim_expires_at' => null,
			)
		);
	}

	/**
	 * Transitions a message to dead_letter with a fixed stable failure code.
	 *
	 * @param int    $id            The message's primary key.
	 * @param string $failure_code  A fixed stable code, never raw API text.
	 *
	 * @return bool
	 */
	public function mark_dead_letter( int $id, string $failure_code ): bool {
		return $this->update(
			$id,
			array(
				'status'            => OutboundMessageStatus::DEAD_LETTER->value,
				'last_failure_code' => $failure_code,
				'dead_lettered_at'  => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * Sets the possible_duplicate_delivery flag. Set once, never cleared.
	 *
	 * @param int $id The message's primary key.
	 *
	 * @return bool
	 */
	public function set_possible_duplicate_delivery( int $id ): bool {
		return $this->update( $id, array( 'possible_duplicate_delivery' => 1 ) );
	}

	/**
	 * Resets a dead-lettered message to a fresh pending attempt, retaining
	 * its still-encrypted content — the admin-triggered "Requeue" action.
	 *
	 * @param int $id The message's primary key.
	 *
	 * @return bool
	 */
	public function requeue( int $id ): bool {
		return $this->update(
			$id,
			array(
				'status'            => OutboundMessageStatus::PENDING->value,
				'attempt_count'     => 0,
				'last_failure_code' => null,
				'dead_lettered_at'  => null,
			)
		);
	}

	/**
	 * Nulls a message's own content ciphertext, retaining its metadata row —
	 * the retention-cleanup content purge (WP9).
	 *
	 * @param int $id The message's primary key.
	 *
	 * @return bool
	 */
	public function purge_body( int $id ): bool {
		return $this->update(
			$id,
			array(
				'body_ciphertext' => null,
				'status'          => OutboundMessageStatus::PURGED->value,
			)
		);
	}

	/**
	 * Permanently deletes a message row — the retention-cleanup row purge (WP9).
	 *
	 * @param int $id The message's primary key.
	 *
	 * @return bool
	 */
	public function delete( int $id ): bool {
		if ( ! $this->schema_health->is_available() ) {
			return false;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::OUTBOUND_MESSAGES_TABLE;

		return false !== $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Messages in a given status older than a given number of days,
	 * measured from created_at. Used by the retention cleanup job (WP9).
	 *
	 * @param OutboundMessageStatus|null $status_or_terminal Null to match any terminal status (sent, dead_letter).
	 * @param int                        $older_than_days     The age threshold, in days.
	 *
	 * @return array<int, OutboundMessage>
	 */
	public function terminal_older_than( ?OutboundMessageStatus $status_or_terminal, int $older_than_days ): array {
		if ( ! $this->schema_health->is_available() ) {
			return array();
		}

		global $wpdb;

		$table  = $wpdb->prefix . Migrator::OUTBOUND_MESSAGES_TABLE;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $older_than_days * DAY_IN_SECONDS ) );

		if ( null !== $status_or_terminal ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s AND created_at < %s", $status_or_terminal->value, $cutoff ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE status IN (%s, %s, %s) AND created_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					OutboundMessageStatus::SENT->value,
					OutboundMessageStatus::DEAD_LETTER->value,
					OutboundMessageStatus::PURGED->value,
					$cutoff
				),
				ARRAY_A
			);
		}

		return array_map( array( $this, 'hydrate' ), null === $rows ? array() : $rows );
	}

	/**
	 * The most recently dead-lettered messages. Used by the bot management
	 * admin screen's dead-letter inspection list (WP10).
	 *
	 * @param int $limit The maximum number of rows to return.
	 *
	 * @return array<int, OutboundMessage>
	 */
	public function recent_dead_letters( int $limit ): array {
		if ( ! $this->schema_health->is_available() ) {
			return array();
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::OUTBOUND_MESSAGES_TABLE;
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY dead_lettered_at DESC LIMIT %d", OutboundMessageStatus::DEAD_LETTER->value, $limit ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return array_map( array( $this, 'hydrate' ), null === $rows ? array() : $rows );
	}

	/**
	 * The current dead-letter count. Used by QueueHealthAlert (WP8).
	 *
	 * @return int
	 */
	public function dead_letter_count(): int {
		if ( ! $this->schema_health->is_available() ) {
			return 0;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::OUTBOUND_MESSAGES_TABLE;

		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", OutboundMessageStatus::DEAD_LETTER->value ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * The count of pending/retry_scheduled/sending messages older than a
	 * given staleness threshold. Used by QueueHealthAlert (WP8).
	 *
	 * @param int $threshold_seconds The staleness threshold, in seconds.
	 *
	 * @return int
	 */
	public function stale_pending_count( int $threshold_seconds ): int {
		if ( ! $this->schema_health->is_available() ) {
			return 0;
		}

		global $wpdb;

		$table  = $wpdb->prefix . Migrator::OUTBOUND_MESSAGES_TABLE;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $threshold_seconds );

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE status IN (%s, %s, %s) AND created_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				OutboundMessageStatus::PENDING->value,
				OutboundMessageStatus::RETRY_SCHEDULED->value,
				OutboundMessageStatus::SENDING->value,
				$cutoff
			)
		);
	}

	/**
	 * Shared partial-update helper.
	 *
	 * @param int                  $id     The message's primary key.
	 * @param array<string, mixed> $fields The columns to update.
	 *
	 * @return bool
	 */
	private function update( int $id, array $fields ): bool {
		if ( ! $this->schema_health->is_available() ) {
			return false;
		}

		global $wpdb;

		$fields['updated_at'] = current_time( 'mysql', true );

		$table   = $wpdb->prefix . Migrator::OUTBOUND_MESSAGES_TABLE;
		$updated = $wpdb->update( $table, $fields, array( 'id' => $id ) );

		return false !== $updated;
	}

	/**
	 * Hydrates one database row into an OutboundMessage.
	 *
	 * @param array<string, mixed> $row The raw database row.
	 *
	 * @return OutboundMessage
	 */
	private function hydrate( array $row ): OutboundMessage {
		return new OutboundMessage(
			(int) $row['id'],
			(string) $row['message_uuid'],
			(int) $row['bot_id'],
			(int) $row['destination_id'],
			null === $row['body_ciphertext'] ? null : (string) $row['body_ciphertext'],
			null === $row['parse_mode'] ? null : (string) $row['parse_mode'],
			OutboundMessageStatus::from( (string) $row['status'] ),
			(int) $row['attempt_count'],
			null === $row['last_failure_code'] ? null : (string) $row['last_failure_code'],
			'1' === (string) $row['possible_duplicate_delivery'] || 1 === (int) $row['possible_duplicate_delivery'],
			null === $row['dead_lettered_at'] ? null : (string) $row['dead_lettered_at'],
			null === $row['telegram_message_id'] ? null : (int) $row['telegram_message_id'],
			(string) $row['created_at'],
			(string) $row['updated_at'],
			null === $row['sent_at'] ? null : (string) $row['sent_at'],
			null === $row['claim_expires_at'] ? null : (string) $row['claim_expires_at']
		);
	}
}
