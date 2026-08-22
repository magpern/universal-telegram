<?php
/**
 * Conversation message persistence.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Conversations;

use UniversalTelegram\Core\Security\CredentialState;
use UniversalTelegram\Core\Security\CredentialUnavailableException;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Persistence\SchemaHealth;

/**
 * CRUD for conversation messages. Encrypts on write and decrypts on read
 * via the existing CredentialVault, bound to a per-message context string
 * so one message's ciphertext can never be relinked to another
 * (docs/adr/0021, M05 plan §9). Checks SchemaHealth::is_available() at its
 * own point of use (docs/adr/0007).
 *
 * Not declared final: test doubles may need to extend this in later work
 * packages, matching NotificationRuleRepository's own precedent.
 */
class MessageRepository {

	/**
	 * Constructor.
	 *
	 * @param SchemaHealth    $schema_health Checked before every operation.
	 * @param CredentialVault $vault         Encrypts/decrypts message bodies.
	 */
	public function __construct(
		private readonly SchemaHealth $schema_health,
		private readonly CredentialVault $vault
	) {}

	/**
	 * The encryption/decryption additional-authenticated-data context for a
	 * given message_uuid.
	 *
	 * @param string $message_uuid The message's own public identifier.
	 *
	 * @return string
	 */
	private function context( string $message_uuid ): string {
		return 'conversation_message:' . $message_uuid;
	}

	/**
	 * Creates a message row, encrypting the plaintext body immediately. The
	 * plaintext is never retained by this method beyond the encrypt() call.
	 *
	 * @param int         $conversation_id The owning conversation.
	 * @param string      $direction       'visitor' or 'operator'.
	 * @param string      $plaintext_body  The message text.
	 * @param string      $delivery_state  stored|sent|failed.
	 * @param int|null    $telegram_message_id The Telegram message id, if already known.
	 * @param string|null $idempotency_key     Client-supplied per-message idempotency key, if any (M06 plan §0).
	 *
	 * @return ConversationMessage|null Null if the schema is unavailable, the
	 *                                  key is unavailable, or the write failed
	 *                                  (including a unique-constraint collision
	 *                                  on (conversation_id, idempotency_key)).
	 */
	public function create(
		int $conversation_id,
		string $direction,
		string $plaintext_body,
		string $delivery_state = 'stored',
		?int $telegram_message_id = null,
		?string $idempotency_key = null
	): ?ConversationMessage {
		if ( ! $this->schema_health->is_available() ) {
			return null;
		}

		$message_uuid = wp_generate_uuid4();

		try {
			$ciphertext = $this->vault->encrypt( $plaintext_body, $this->context( $message_uuid ) );
		} catch ( CredentialUnavailableException $exception ) {
			return null;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::CONVERSATION_MESSAGES_TABLE;

		$inserted = $wpdb->insert(
			$table,
			array(
				'conversation_id'     => $conversation_id,
				'message_uuid'        => $message_uuid,
				'direction'           => $direction,
				'body_ciphertext'     => $ciphertext,
				'telegram_message_id' => $telegram_message_id,
				'delivery_state'      => $delivery_state,
				'idempotency_key'     => $idempotency_key,
				'created_at'          => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return null;
		}

		return $this->find( (int) $wpdb->insert_id );
	}

	/**
	 * Finds a message by its owning conversation and per-message
	 * idempotency key — the sole lookup path a message-post replay ever
	 * uses (M06 plan §0, ADR-0021 amendment).
	 *
	 * @param int    $conversation_id The owning conversation.
	 * @param string $idempotency_key The client-supplied per-message idempotency key.
	 *
	 * @return ConversationMessage|null
	 */
	public function find_by_idempotency_key( int $conversation_id, string $idempotency_key ): ?ConversationMessage {
		if ( ! $this->schema_health->is_available() ) {
			return null;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::CONVERSATION_MESSAGES_TABLE;
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE conversation_id = %d AND idempotency_key = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$conversation_id,
				$idempotency_key
			),
			ARRAY_A
		);

		return null === $row ? null : $this->hydrate( $row );
	}

	/**
	 * Records that a visitor message was successfully handed off to the
	 * existing outbound pipeline, correlating it to the resulting
	 * universal_telegram_outbound_messages row (M05 plan §5).
	 *
	 * @param int    $id                    The conversation message's primary key.
	 * @param string $outbound_message_uuid The created outbound message's own uuid.
	 *
	 * @return bool
	 */
	public function mark_routed( int $id, string $outbound_message_uuid ): bool {
		if ( ! $this->schema_health->is_available() ) {
			return false;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::CONVERSATION_MESSAGES_TABLE;

		$updated = $wpdb->update(
			$table,
			array(
				'outbound_message_uuid' => $outbound_message_uuid,
				'delivery_state'        => 'sent',
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		return false !== $updated;
	}

	/**
	 * Records that a visitor message's deferred routing wait exceeded its
	 * bounded maximum — the message remains stored and visible, never
	 * silently dropped (M05 plan §5).
	 *
	 * @param int $id The conversation message's primary key.
	 *
	 * @return bool
	 */
	public function mark_delivery_failed( int $id ): bool {
		if ( ! $this->schema_health->is_available() ) {
			return false;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::CONVERSATION_MESSAGES_TABLE;

		$updated = $wpdb->update(
			$table,
			array( 'delivery_state' => 'failed' ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);

		return false !== $updated;
	}

	/**
	 * Nulls every message's own body_ciphertext for one conversation —
	 * retention cleanup's 30-day-from-archival step (M05 plan §9). Already-
	 * null rows are left untouched, making a rerun a safe no-op.
	 *
	 * @param int $conversation_id The owning conversation.
	 *
	 * @return bool
	 */
	public function null_bodies_for_conversation( int $conversation_id ): bool {
		if ( ! $this->schema_health->is_available() ) {
			return false;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::CONVERSATION_MESSAGES_TABLE;

		$updated = $wpdb->update(
			$table,
			array( 'body_ciphertext' => null ),
			array( 'conversation_id' => $conversation_id ),
			array( '%s' ),
			array( '%d' )
		);

		return false !== $updated;
	}

	/**
	 * Permanently deletes every message row for one conversation —
	 * retention cleanup's 90-day-from-archival step, always run in the same
	 * pass as deleting the conversation row itself (M05 plan §9).
	 *
	 * @param int $conversation_id The owning conversation.
	 *
	 * @return bool
	 */
	public function delete_for_conversation( int $conversation_id ): bool {
		if ( ! $this->schema_health->is_available() ) {
			return false;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::CONVERSATION_MESSAGES_TABLE;

		return false !== $wpdb->delete( $table, array( 'conversation_id' => $conversation_id ), array( '%d' ) );
	}

	/**
	 * Whether $message is the conversation's first accepted message,
	 * determined deterministically by insertion sequence (the lowest id
	 * for the owning conversation), never a request-scoped flag — so it
	 * survives retries and idempotency replay correctly (M06.3, ADR-0024).
	 * The sole gate for whether the one-time Telegram context header is
	 * ever added.
	 *
	 * @param ConversationMessage $message The message to check.
	 *
	 * @return bool
	 */
	public function is_first_message( ConversationMessage $message ): bool {
		if ( ! $this->schema_health->is_available() ) {
			return false;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::CONVERSATION_MESSAGES_TABLE;

		$min_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MIN(id) FROM {$table} WHERE conversation_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$message->conversation_id()
			)
		);

		return null !== $min_id && (int) $min_id === $message->id();
	}

	/**
	 * Finds a message by primary key.
	 *
	 * @param int $id The message's primary key.
	 *
	 * @return ConversationMessage|null
	 */
	public function find( int $id ): ?ConversationMessage {
		if ( ! $this->schema_health->is_available() ) {
			return null;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::CONVERSATION_MESSAGES_TABLE;
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return null === $row ? null : $this->hydrate( $row );
	}

	/**
	 * The keyset-cursor page of messages for a conversation: every message
	 * with id > since_id, ascending by id (M05 plan §4).
	 *
	 * @param int $conversation_id The owning conversation.
	 * @param int $since_id        The highest id the caller has already seen; 0 for the first poll.
	 *
	 * @return array<int, ConversationMessage>
	 */
	public function messages_since( int $conversation_id, int $since_id ): array {
		if ( ! $this->schema_health->is_available() ) {
			return array();
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::CONVERSATION_MESSAGES_TABLE;
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE conversation_id = %d AND id > %d ORDER BY id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$conversation_id,
				$since_id
			),
			ARRAY_A
		);

		return array_map( array( $this, 'hydrate' ), null === $rows ? array() : $rows );
	}

	/**
	 * Decrypts a message's body for the authenticated caller of one
	 * request. A decrypt failure never modifies the stored ciphertext and
	 * is reported here as null, never an exception — callers render an
	 * opaque placeholder, never leak plaintext or throw (M05 plan §9).
	 *
	 * @param ConversationMessage $message The message to decrypt.
	 *
	 * @return string|null
	 */
	public function decrypt( ConversationMessage $message ): ?string {
		if ( null === $message->body_ciphertext() ) {
			return null;
		}

		$result = $this->vault->decrypt( $message->body_ciphertext(), $this->context( $message->message_uuid() ) );

		return CredentialState::AVAILABLE === $result->state() ? $result->plaintext() : null;
	}

	/**
	 * Hydrates one database row into a ConversationMessage.
	 *
	 * @param array<string, mixed> $row The raw database row.
	 *
	 * @return ConversationMessage
	 */
	private function hydrate( array $row ): ConversationMessage {
		return new ConversationMessage(
			(int) $row['id'],
			(int) $row['conversation_id'],
			(string) $row['message_uuid'],
			(string) $row['direction'],
			null === $row['body_ciphertext'] ? null : (string) $row['body_ciphertext'],
			null === $row['outbound_message_uuid'] ? null : (string) $row['outbound_message_uuid'],
			null === $row['telegram_message_id'] ? null : (int) $row['telegram_message_id'],
			(string) $row['delivery_state'],
			(string) $row['created_at'],
			null === $row['idempotency_key'] ? null : (string) $row['idempotency_key']
		);
	}
}
