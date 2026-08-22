<?php
/**
 * Conversation persistence.
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
 * CRUD and status transitions for conversations. Checks
 * SchemaHealth::is_available() at its own point of use (docs/adr/0007).
 * find_by_uuid() is the only lookup path a REST request ever uses — there
 * is no lookup-by-token step anywhere in this class (M05 plan §3).
 *
 * Not declared final: test doubles may need to extend this in later work
 * packages, matching NotificationRuleRepository's own precedent.
 */
class ConversationRepository {

	/**
	 * Constructor.
	 *
	 * @param SchemaHealth    $schema_health Checked before every operation.
	 * @param CredentialVault $vault         Encrypts/decrypts the visitor display name (M06.3, ADR-0024).
	 */
	public function __construct(
		private readonly SchemaHealth $schema_health,
		private readonly CredentialVault $vault
	) {}

	/**
	 * The encryption/decryption additional-authenticated-data context for a
	 * conversation's own display name — distinct from MessageRepository's
	 * per-message context, so this ciphertext can never be relinked against
	 * a message body or another conversation's name (M06.3, ADR-0024).
	 *
	 * @param string $conversation_uuid The conversation's own public identifier.
	 *
	 * @return string
	 */
	private function display_name_context( string $conversation_uuid ): string {
		return 'conversation:' . $conversation_uuid . ':display_name';
	}

	/**
	 * Creates a new conversation in status `new`, topic_creation_state
	 * `none`. The caller supplies the already-generated conversation_uuid
	 * and secret_hash (VisitorTokenGenerator) — this method never generates
	 * or sees the plaintext secret.
	 *
	 * @param string      $conversation_uuid     Public, opaque identifier.
	 * @param string      $secret_hash           password_hash() of the bearer secret.
	 * @param int         $bot_id                The Telegram bot this conversation belongs to.
	 * @param string|null $chat_profile          The configured profile requested at start, if any.
	 * @param string|null $start_idempotency_key The client-supplied start idempotency key, if any (M06 plan §0).
	 *
	 * @return Conversation|null Null if the schema is unavailable or the write failed
	 *                           (including a unique-constraint collision on start_idempotency_key).
	 */
	public function create( string $conversation_uuid, string $secret_hash, int $bot_id, ?string $chat_profile, ?string $start_idempotency_key = null ): ?Conversation {
		if ( ! $this->schema_health->is_available() ) {
			return null;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::CONVERSATIONS_TABLE;
		$now   = current_time( 'mysql', true );

		$inserted = $wpdb->insert(
			$table,
			array(
				'conversation_uuid'      => $conversation_uuid,
				'secret_hash'            => $secret_hash,
				'bot_id'                 => $bot_id,
				'chat_profile'           => $chat_profile,
				'status'                 => ConversationStatus::NEW,
				'topic_creation_state'   => 'none',
				'ai_participation_state' => 'none',
				'consent_state'          => 'unknown',
				'start_idempotency_key'  => $start_idempotency_key,
				'created_at'             => $now,
				'updated_at'             => $now,
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return null;
		}

		return $this->find( (int) $wpdb->insert_id );
	}

	/**
	 * Finds a conversation by primary key.
	 *
	 * @param int $id The conversation's primary key.
	 *
	 * @return Conversation|null
	 */
	public function find( int $id ): ?Conversation {
		if ( ! $this->schema_health->is_available() ) {
			return null;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::CONVERSATIONS_TABLE;
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return null === $row ? null : $this->hydrate( $row );
	}

	/**
	 * Finds a conversation by its public conversation_uuid — the only
	 * lookup key this boundary ever uses (M05 plan §3).
	 *
	 * @param string $conversation_uuid The public, opaque identifier.
	 *
	 * @return Conversation|null
	 */
	public function find_by_uuid( string $conversation_uuid ): ?Conversation {
		if ( ! $this->schema_health->is_available() ) {
			return null;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::CONVERSATIONS_TABLE;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE conversation_uuid = %s", $conversation_uuid ), ARRAY_A );

		return null === $row ? null : $this->hydrate( $row );
	}

	/**
	 * Finds the conversation created by a given start idempotency key — the
	 * sole lookup path a start-replay ever uses (M06 plan §0, ADR-0021
	 * amendment). Never falls back to any other lookup.
	 *
	 * @param string $start_idempotency_key The client-supplied start idempotency key.
	 *
	 * @return Conversation|null
	 */
	public function find_by_start_idempotency_key( string $start_idempotency_key ): ?Conversation {
		if ( ! $this->schema_health->is_available() ) {
			return null;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::CONVERSATIONS_TABLE;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE start_idempotency_key = %s", $start_idempotency_key ), ARRAY_A );

		return null === $row ? null : $this->hydrate( $row );
	}

	/**
	 * Finds the conversation whose Telegram forum topic this is — the
	 * "known-topic mapping" gate of the inbound webhook's conversation-scoped
	 * routing (M05 plan §6). Only a conversation with a genuinely created
	 * topic can ever match; a 'pending' or 'failed' topic_creation_state
	 * never does, since no reply could legitimately reference it yet.
	 *
	 * @param int $bot_id            The receiving bot's primary key.
	 * @param int $telegram_topic_id The Telegram forum topic id from the inbound update.
	 *
	 * @return Conversation|null
	 */
	public function find_by_topic( int $bot_id, int $telegram_topic_id ): ?Conversation {
		if ( ! $this->schema_health->is_available() ) {
			return null;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::CONVERSATIONS_TABLE;
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE bot_id = %d AND telegram_topic_id = %d AND topic_creation_state = 'created'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$bot_id,
				$telegram_topic_id
			),
			ARRAY_A
		);

		return null === $row ? null : $this->hydrate( $row );
	}

	/**
	 * Applies a status transition, gated on the frozen transition map
	 * (ConversationStatus) and an atomic conditional update keyed on the
	 * conversation's currently recorded status, so a stale caller can never
	 * clobber a transition that already happened concurrently.
	 *
	 * @param int    $id   The conversation's primary key.
	 * @param string $from The status the caller believes is current.
	 * @param string $to   The proposed new status.
	 *
	 * @return bool True only if the map allows the transition and the
	 *              conditional update actually matched a row.
	 */
	public function transition( int $id, string $from, string $to ): bool {
		if ( ! ConversationStatus::is_valid_transition( $from, $to ) ) {
			return false;
		}

		if ( ! $this->schema_health->is_available() ) {
			return false;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::CONVERSATIONS_TABLE;
		$now   = current_time( 'mysql', true );

		$data    = array(
			'status'     => $to,
			'updated_at' => $now,
		);
		$formats = array( '%s', '%s' );

		if ( ConversationStatus::RESOLVED === $to ) {
			$data['resolved_at'] = $now;
			$formats[]           = '%s';
		}

		$updated = $wpdb->update(
			$table,
			$data,
			array(
				'id'     => $id,
				'status' => $from,
			),
			$formats,
			array( '%d', '%s' )
		);

		return false !== $updated && $updated > 0;
	}

	/**
	 * The atomic compare-and-set guard that makes topic creation idempotent
	 * and concurrency-safe: only the caller whose UPDATE actually matches a
	 * row may proceed to call `createForumTopic` (M05 plan §5,
	 * docs/adr/0021). Beyond the original `none -> pending` case, also
	 * allows reclaiming a `pending` row whose own lease has already
	 * expired — a prior claimant crashed after winning the guard but
	 * before ever reaching a terminal state (M06.2 corrective plan v2 §3.1,
	 * ADR-0023 amendment). Terminal states (`created`/`failed`) are never
	 * matched by either branch — once reached, no claim can ever be
	 * re-acquired. Retries, duplicate first-message submissions, or
	 * concurrent requests can therefore never produce two topics for one
	 * conversation.
	 *
	 * Uses a literal raw-SQL `UPDATE ... WHERE ... OR (...)` rather than
	 * `$wpdb->update()`'s compound-WHERE helper, because that helper can
	 * only AND its WHERE columns together — the reclaim-on-expiry branch
	 * genuinely requires an OR.
	 *
	 * @param int $id            The conversation's primary key.
	 * @param int $lease_seconds How long this caller's claim remains valid.
	 *
	 * @return string|null The won claim's own lease-expiry timestamp
	 *                       (threaded into the enqueued job so it can later
	 *                       verify it still owns this specific claim window
	 *                       — M06.2 corrective plan v2 §3.1), or null if
	 *                       this call did not win the compare-and-set.
	 */
	public function try_begin_topic_creation( int $id, int $lease_seconds = 15 ): ?string {
		if ( ! $this->schema_health->is_available() ) {
			return null;
		}

		global $wpdb;

		$table        = $wpdb->prefix . Migrator::CONVERSATIONS_TABLE;
		$now          = current_time( 'mysql', true );
		$lease_expiry = gmdate( 'Y-m-d H:i:s', strtotime( $now ) + $lease_seconds );

		$updated = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE {$table}
					SET topic_creation_state = %s, topic_claim_expires_at = %s, updated_at = %s
					WHERE id = %d
					  AND ( topic_creation_state = %s
					        OR ( topic_creation_state = %s AND topic_claim_expires_at IS NOT NULL AND topic_claim_expires_at < %s ) )",
				'pending',
				$lease_expiry,
				$now,
				$id,
				'none',
				'pending',
				$now
			)
		);

		return ( false !== $updated && $updated > 0 ) ? $lease_expiry : null;
	}

	/**
	 * Records a successful topic creation: the topic id, the destination
	 * row it now routes through, and the `new -> open` status transition
	 * (M05 plan §5, §7).
	 *
	 * @param int $id                The conversation's primary key.
	 * @param int $telegram_topic_id The Telegram forum topic id.
	 * @param int $destination_id    The conversation's own destination row id.
	 *
	 * @return bool
	 */
	public function mark_topic_created( int $id, int $telegram_topic_id, int $destination_id ): bool {
		if ( ! $this->schema_health->is_available() ) {
			return false;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::CONVERSATIONS_TABLE;

		$updated = $wpdb->update(
			$table,
			array(
				'topic_creation_state'   => 'created',
				'telegram_topic_id'      => $telegram_topic_id,
				'destination_id'         => $destination_id,
				'topic_claim_expires_at' => null,
				'updated_at'             => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%s', '%d', '%d', '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return false;
		}

		$this->transition( $id, ConversationStatus::NEW, ConversationStatus::OPEN );

		return true;
	}

	/**
	 * Records a bounded-retry exhaustion: topic creation ends 'failed', a
	 * surfaced degraded state, never a silent drop (M05 plan §5).
	 *
	 * @param int $id The conversation's primary key.
	 *
	 * @return bool
	 */
	public function mark_topic_failed( int $id ): bool {
		if ( ! $this->schema_health->is_available() ) {
			return false;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::CONVERSATIONS_TABLE;

		$updated = $wpdb->update(
			$table,
			array(
				'topic_creation_state'   => 'failed',
				'topic_claim_expires_at' => null,
				'updated_at'             => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		return false !== $updated;
	}

	/**
	 * Gracefully releases a held topic-creation claim without waiting out
	 * its lease, reverting the row to `none` so the next attempt (an
	 * in-process fallback or the durable queue) may reclaim immediately
	 * (M06.2 corrective plan v2 §3.1).
	 *
	 * @param int $id The conversation's primary key.
	 *
	 * @return bool
	 */
	public function release_topic_claim( int $id ): bool {
		if ( ! $this->schema_health->is_available() ) {
			return false;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::CONVERSATIONS_TABLE;

		$updated = $wpdb->update(
			$table,
			array(
				'topic_creation_state'   => 'none',
				'topic_claim_expires_at' => null,
				'updated_at'             => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		return false !== $updated;
	}

	/**
	 * Sets the conversation's own destination row id, once its Telegram
	 * topic exists.
	 *
	 * @param int $id             The conversation's primary key.
	 * @param int $destination_id The destination row id.
	 *
	 * @return bool
	 */
	public function set_destination( int $id, int $destination_id ): bool {
		if ( ! $this->schema_health->is_available() ) {
			return false;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::CONVERSATIONS_TABLE;

		$updated = $wpdb->update(
			$table,
			array(
				'destination_id' => $destination_id,
				'updated_at'     => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%d', '%s' ),
			array( '%d' )
		);

		return false !== $updated;
	}

	/**
	 * Revokes a conversation's bearer secret by nulling its hash — the only
	 * revocation act this boundary performs; the row itself is never
	 * deleted as the revocation act (M05 plan §3).
	 *
	 * @param int $id The conversation's primary key.
	 *
	 * @return bool
	 */
	public function revoke_secret( int $id ): bool {
		if ( ! $this->schema_health->is_available() ) {
			return false;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::CONVERSATIONS_TABLE;

		$updated = $wpdb->update(
			$table,
			array(
				'secret_hash' => null,
				'updated_at'  => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		return false !== $updated;
	}

	/**
	 * Assigns an operator to a conversation. Exists only as the domain
	 * method the charter's "Status and assignment" deliverable requires;
	 * no M05 code path ever calls it — reserved for M7's operator UI
	 * (M05 plan §7).
	 *
	 * @param int $id          The conversation's primary key.
	 * @param int $operator_id The WordPress user id of the assigned operator.
	 *
	 * @return bool
	 */
	public function assign( int $id, int $operator_id ): bool {
		if ( ! $this->schema_health->is_available() ) {
			return false;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::CONVERSATIONS_TABLE;

		$updated = $wpdb->update(
			$table,
			array(
				'assigned_operator_id' => $operator_id,
				'updated_at'           => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%d', '%s' ),
			array( '%d' )
		);

		return false !== $updated;
	}

	/**
	 * Every conversation currently `resolved` — retention cleanup's own
	 * source list for the `resolved -> archived` transition, the sole
	 * code path in this plugin ever permitted to perform it (M05 plan §7).
	 *
	 * @return array<int, Conversation>
	 */
	public function resolved(): array {
		if ( ! $this->schema_health->is_available() ) {
			return array();
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::CONVERSATIONS_TABLE;
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s", ConversationStatus::RESOLVED ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return array_map( array( $this, 'hydrate' ), null === $rows ? array() : $rows );
	}

	/**
	 * Every `archived` conversation whose own `updated_at` — frozen at the
	 * moment of archival, since `archived` is a terminal status no further
	 * transition ever touches — is older than the given number of days
	 * (M05 plan §9).
	 *
	 * @param int $days The retention threshold, in days.
	 *
	 * @return array<int, Conversation>
	 */
	public function archived_older_than( int $days ): array {
		if ( ! $this->schema_health->is_available() ) {
			return array();
		}

		global $wpdb;

		$table     = $wpdb->prefix . Migrator::CONVERSATIONS_TABLE;
		$threshold = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		$rows      = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = %s AND updated_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				ConversationStatus::ARCHIVED,
				$threshold
			),
			ARRAY_A
		);

		return array_map( array( $this, 'hydrate' ), null === $rows ? array() : $rows );
	}

	/**
	 * Permanently deletes a conversation row. Retention cleanup's own final
	 * step, always preceded by deleting the conversation's own messages and
	 * destination row in the same pass (M05 plan §9).
	 *
	 * @param int $id The conversation's primary key.
	 *
	 * @return bool
	 */
	public function delete( int $id ): bool {
		if ( ! $this->schema_health->is_available() ) {
			return false;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::CONVERSATIONS_TABLE;

		return false !== $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Hydrates one database row into a Conversation.
	 *
	 * @param array<string, mixed> $row The raw database row.
	 *
	 * @return Conversation
	 */
	private function hydrate( array $row ): Conversation {
		return new Conversation(
			(int) $row['id'],
			(string) $row['conversation_uuid'],
			null === $row['secret_hash'] ? null : (string) $row['secret_hash'],
			(int) $row['bot_id'],
			null === $row['destination_id'] ? null : (int) $row['destination_id'],
			null === $row['chat_profile'] ? null : (string) $row['chat_profile'],
			(string) $row['status'],
			null === $row['assigned_operator_id'] ? null : (int) $row['assigned_operator_id'],
			(string) $row['topic_creation_state'],
			null === $row['telegram_topic_id'] ? null : (int) $row['telegram_topic_id'],
			(string) $row['ai_participation_state'],
			(string) $row['consent_state'],
			null === $row['session_ref'] ? null : (string) $row['session_ref'],
			(string) $row['created_at'],
			(string) $row['updated_at'],
			null === $row['resolved_at'] ? null : (string) $row['resolved_at'],
			null === $row['expires_at'] ? null : (string) $row['expires_at'],
			null === $row['start_idempotency_key'] ? null : (string) $row['start_idempotency_key'],
			null === $row['topic_claim_expires_at'] ? null : (string) $row['topic_claim_expires_at'],
			null === $row['display_name_ciphertext'] ? null : (string) $row['display_name_ciphertext']
		);
	}

	/**
	 * Encrypts and stores a visitor display name — write-once: a no-op
	 * (returns true without touching the row) if a name is already stored,
	 * so a caller can never overwrite an established name (M06.3, ADR-0024).
	 * The plaintext is never retained by this method beyond the encrypt()
	 * call, and is never itself returned or logged.
	 *
	 * @param Conversation $conversation    The conversation, already verified to have no stored name by the caller's own display_name_required() check.
	 * @param string       $plaintext_name  The trimmed, length-validated display name.
	 *
	 * @return bool True on success or on the already-stored no-op case; false only on encryption or write failure.
	 */
	public function store_display_name( Conversation $conversation, string $plaintext_name ): bool {
		if ( ! $conversation->display_name_required() ) {
			return true;
		}

		if ( ! $this->schema_health->is_available() ) {
			return false;
		}

		try {
			$ciphertext = $this->vault->encrypt( $plaintext_name, $this->display_name_context( $conversation->conversation_uuid() ) );
		} catch ( CredentialUnavailableException $exception ) {
			return false;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::CONVERSATIONS_TABLE;

		$updated = $wpdb->update(
			$table,
			array(
				'display_name_ciphertext' => $ciphertext,
				'updated_at'              => current_time( 'mysql', true ),
			),
			array(
				'id'                      => $conversation->id(),
				'display_name_ciphertext' => null,
			),
			array( '%s', '%s' ),
			array( '%d', '%s' )
		);

		return false !== $updated && $updated > 0;
	}

	/**
	 * Decrypts a conversation's stored display name for the one shared
	 * send path that is ever permitted to disclose it into Telegram (the
	 * topic title and the first-message context header — M06.3, ADR-0024).
	 * A decrypt failure never modifies the stored ciphertext and is
	 * reported here as null, never an exception — callers fall back to the
	 * pre-M06.3 topic-title literal, never leaking plaintext or throwing.
	 *
	 * @param Conversation $conversation The conversation to decrypt the display name for.
	 *
	 * @return string|null
	 */
	public function decrypt_display_name( Conversation $conversation ): ?string {
		if ( null === $conversation->display_name_ciphertext() ) {
			return null;
		}

		$result = $this->vault->decrypt( $conversation->display_name_ciphertext(), $this->display_name_context( $conversation->conversation_uuid() ) );

		return CredentialState::AVAILABLE === $result->state() ? $result->plaintext() : null;
	}

	/**
	 * Every conversation currently open or waiting (never already resolved
	 * or archived) whose own updated_at — already bumped by every existing
	 * status/topic-state transition, including the visitor-message and
	 * operator-webhook-reply transitions — is older than $days. The sole
	 * eligibility source for M06.3's inactivity auto-archival step
	 * (ADR-0024): callers must route each match through the existing
	 * transition() compare-and-set to 'resolved', never a raw status write,
	 * and this query itself never matches an already-resolved or archived
	 * row, so this mechanism can never reopen or reprocess one.
	 *
	 * @param int $days Inactivity threshold, in days.
	 *
	 * @return array<int, Conversation>
	 */
	public function inactive_open_conversations( int $days ): array {
		if ( ! $this->schema_health->is_available() ) {
			return array();
		}

		global $wpdb;

		$table    = $wpdb->prefix . Migrator::CONVERSATIONS_TABLE;
		$cutoff   = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql', true ) ) - ( $days * DAY_IN_SECONDS ) );
		$statuses = array( ConversationStatus::OPEN, ConversationStatus::WAITING_FOR_VISITOR, ConversationStatus::WAITING_FOR_OPERATOR );

		$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE status IN ({$placeholders}) AND updated_at < %s",
				array_merge( $statuses, array( $cutoff ) )
			),
			ARRAY_A
		);

		return array_map( array( $this, 'hydrate' ), $rows );
	}

	/**
	 * Every distinct destination id this bot's conversations have ever
	 * created a topic against — the sole source BotManagementPage uses to
	 * exclude conversation-created destinations from the manually
	 * configured destination list and its "Send test message" action
	 * (M06.3, ADR-0024). No schema change: reuses the existing
	 * destination_id column already stored on conversations.
	 *
	 * @param int $bot_id The bot's primary key.
	 *
	 * @return array<int, int>
	 */
	public function destination_ids_for_bot( int $bot_id ): array {
		if ( ! $this->schema_health->is_available() ) {
			return array();
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::CONVERSATIONS_TABLE;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT DISTINCT destination_id FROM {$table} WHERE bot_id = %d AND destination_id IS NOT NULL",
				$bot_id
			)
		);

		return array_map( 'intval', $ids );
	}
}
