<?php
/**
 * AI draft persistence.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\AI\Draft;

use UniversalTelegram\Core\Security\CredentialResult;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Persistence\SchemaHealth;

/**
 * CredentialVault-backed CRUD for universal_telegram_ai_drafts
 * (docs/adr/0028 decisions 4–6). Referenced only by the fixed six-class
 * allow-list this ADR establishes: AI\Draft\DraftRequestHandler,
 * AI\Draft\AIDraftGenerationHandler, AI\Draft\AiDraftLeaseSweep,
 * Administration\AI\ConversationDraftPanel, and
 * Administration\AI\AIDiagnosticsPanel — enforced by
 * StructuralBoundariesTest, never by convention alone. Checks
 * SchemaHealth::is_available() at its own point of use, matching every
 * other M00+ database-touching service (docs/adr/0007).
 */
final class AiDraftRepository {

	private const CONTEXT_PREFIX = 'ai.draft.body:';

	/**
	 * Constructor.
	 *
	 * @param SchemaHealth    $schema_health    Checked before every operation.
	 * @param CredentialVault $credential_vault Encrypts/decrypts the draft body.
	 */
	public function __construct(
		private readonly SchemaHealth $schema_health,
		private readonly CredentialVault $credential_vault
	) {}

	/**
	 * Inserts a new `queued` row. Callers needing the one-active-draft
	 * guarantee (docs/adr/0028 decision 5) must use
	 * DraftRequestHandler::request(), not this method directly — this is
	 * an unconditional insert with no locking of its own.
	 *
	 * @param int    $conversation_id       The owning conversation.
	 * @param int    $requested_by_user_id  The requesting operator.
	 * @param string $provider              Traceability copy at request time.
	 * @param string $model                 Traceability copy at request time.
	 * @param string $prompt_policy_version The prompt policy version in effect.
	 *
	 * @return AiDraft|null Null if the schema is unavailable or the insert failed.
	 */
	public function create(
		int $conversation_id,
		int $requested_by_user_id,
		string $provider,
		string $model,
		string $prompt_policy_version
	): ?AiDraft {
		if ( ! $this->schema_health->is_available() ) {
			return null;
		}

		global $wpdb;

		$draft_uuid = wp_generate_uuid4();
		$now        = current_time( 'mysql', true );

		$inserted = $wpdb->insert(
			$wpdb->prefix . Migrator::AI_DRAFTS_TABLE,
			array(
				'draft_uuid'             => $draft_uuid,
				'conversation_id'        => $conversation_id,
				'status'                 => 'queued',
				'provider'               => $provider,
				'model'                  => $model,
				'prompt_policy_version'  => $prompt_policy_version,
				'requested_by_user_id'   => $requested_by_user_id,
				'attempt_count'          => 0,
				'created_at'             => $now,
				'updated_at'             => $now,
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return null;
		}

		return $this->find( (int) $wpdb->insert_id );
	}

	/**
	 * @param int $id Primary key.
	 *
	 * @return AiDraft|null
	 */
	public function find( int $id ): ?AiDraft {
		if ( ! $this->schema_health->is_available() ) {
			return null;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::AI_DRAFTS_TABLE;
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return null === $row ? null : $this->hydrate( $row );
	}

	/**
	 * @param string $draft_uuid The opaque queue/reference identifier.
	 *
	 * @return AiDraft|null
	 */
	public function find_by_uuid( string $draft_uuid ): ?AiDraft {
		if ( ! $this->schema_health->is_available() ) {
			return null;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::AI_DRAFTS_TABLE;
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE draft_uuid = %s", $draft_uuid ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return null === $row ? null : $this->hydrate( $row );
	}

	/**
	 * The `queued`/`generating` draft for a conversation, if any
	 * (docs/adr/0028 decision 5 — at most one at a time).
	 *
	 * @param int $conversation_id The owning conversation.
	 *
	 * @return AiDraft|null
	 */
	public function find_active_for_conversation( int $conversation_id ): ?AiDraft {
		return $this->find_one_with_status( $conversation_id, array( 'queued', 'generating' ) );
	}

	/**
	 * The `generated`/`reviewed`/`approved` draft retained for a
	 * conversation, if any — a new request must be rejected while one is
	 * retained, until the operator explicitly discards it (§3.2).
	 *
	 * @param int $conversation_id The owning conversation.
	 *
	 * @return AiDraft|null
	 */
	public function find_retained_for_conversation( int $conversation_id ): ?AiDraft {
		return $this->find_one_with_status( $conversation_id, array( 'generated', 'reviewed', 'approved' ) );
	}

	/**
	 * The most recently `failed` draft for a conversation, if any — used
	 * to enforce the 30-second post-failure cooldown (§3.2).
	 *
	 * @param int $conversation_id The owning conversation.
	 *
	 * @return AiDraft|null
	 */
	public function most_recent_failed_for_conversation( int $conversation_id ): ?AiDraft {
		if ( ! $this->schema_health->is_available() ) {
			return null;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::AI_DRAFTS_TABLE;
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE conversation_id = %d AND status = 'failed' ORDER BY updated_at DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$conversation_id
			),
			ARRAY_A
		);

		return null === $row ? null : $this->hydrate( $row );
	}

	/**
	 * Every draft for a conversation, most recent first — the operator
	 * review UI's draft history list.
	 *
	 * @param int $conversation_id The owning conversation.
	 *
	 * @return array<int, AiDraft>
	 */
	public function list_for_conversation( int $conversation_id ): array {
		if ( ! $this->schema_health->is_available() ) {
			return array();
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::AI_DRAFTS_TABLE;
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE conversation_id = %d ORDER BY created_at DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$conversation_id
			),
			ARRAY_A
		);

		return array_map( array( $this, 'hydrate' ), null === $rows ? array() : $rows );
	}

	/**
	 * Decrypts a draft's body, for the operator review UI only.
	 *
	 * @param AiDraft $draft The draft to decrypt.
	 *
	 * @return CredentialResult|null Null if no body is stored yet.
	 */
	public function decrypt_body( AiDraft $draft ): ?CredentialResult {
		if ( null === $draft->body_ciphertext() ) {
			return null;
		}

		return $this->credential_vault->decrypt( $draft->body_ciphertext(), self::CONTEXT_PREFIX . $draft->draft_uuid() );
	}

	/**
	 * Marks a `generated` draft `reviewed`.
	 *
	 * @param int $id                  Primary key.
	 * @param int $reviewed_by_user_id The reviewing operator.
	 *
	 * @return bool
	 */
	public function mark_reviewed( int $id, int $reviewed_by_user_id ): bool {
		return $this->transition_from(
			$id,
			array( 'generated' ),
			array(
				'status'              => 'reviewed',
				'reviewed_by_user_id' => $reviewed_by_user_id,
				'reviewed_at'         => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * Marks a draft `approved` — audit-trail only, never triggers a send
	 * (docs/adr/0028 decision 6).
	 *
	 * @param int $id                  Primary key.
	 * @param int $reviewed_by_user_id The approving operator.
	 *
	 * @return bool
	 */
	public function mark_approved( int $id, int $reviewed_by_user_id ): bool {
		return $this->transition_from(
			$id,
			array( 'generated', 'reviewed' ),
			array(
				'status'              => 'approved',
				'reviewed_by_user_id' => $reviewed_by_user_id,
				'reviewed_at'         => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * Marks a `generated`/`reviewed` draft `discarded`. No cooldown
	 * applies afterward (§3.2).
	 *
	 * @param int $id                  Primary key.
	 * @param int $reviewed_by_user_id The discarding operator.
	 *
	 * @return bool
	 */
	public function mark_discarded( int $id, int $reviewed_by_user_id ): bool {
		return $this->transition_from(
			$id,
			array( 'generated', 'reviewed' ),
			array(
				'status'              => 'discarded',
				'reviewed_by_user_id' => $reviewed_by_user_id,
				'reviewed_at'         => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * A compare-and-set transition: succeeds only if the row is currently
	 * in one of the expected statuses.
	 *
	 * @param int                  $id       Primary key.
	 * @param array<int, string>   $from     Expected current statuses.
	 * @param array<string, mixed> $fields   Fields to set, including the new status.
	 *
	 * @return bool
	 */
	private function transition_from( int $id, array $from, array $fields ): bool {
		if ( ! $this->schema_health->is_available() ) {
			return false;
		}

		global $wpdb;

		$table        = $wpdb->prefix . Migrator::AI_DRAFTS_TABLE;
		$placeholders = implode( ',', array_fill( 0, count( $from ), '%s' ) );

		$set_clauses = array();
		$set_values  = array();
		foreach ( $fields as $column => $value ) {
			$set_clauses[] = "{$column} = %s";
			$set_values[]  = $value;
		}
		$set_clauses[] = 'updated_at = %s';
		$set_values[]  = current_time( 'mysql', true );

		$sql = "UPDATE {$table} SET " . implode( ', ', $set_clauses ) . " WHERE id = %d AND status IN ({$placeholders})";

		$updated = $wpdb->query(
			$wpdb->prepare( $sql, array_merge( $set_values, array( $id ), $from ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		return false !== $updated && $updated > 0;
	}

	/**
	 * @param int                $conversation_id The owning conversation.
	 * @param array<int, string> $statuses        Statuses to match.
	 *
	 * @return AiDraft|null
	 */
	private function find_one_with_status( int $conversation_id, array $statuses ): ?AiDraft {
		if ( ! $this->schema_health->is_available() ) {
			return null;
		}

		global $wpdb;

		$table        = $wpdb->prefix . Migrator::AI_DRAFTS_TABLE;
		$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE conversation_id = %d AND status IN ({$placeholders}) ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				array_merge( array( $conversation_id ), $statuses )
			),
			ARRAY_A
		);

		return null === $row ? null : $this->hydrate( $row );
	}

	/**
	 * Builds a value object from a raw database row.
	 *
	 * @param array<string, mixed> $row Raw row from $wpdb->get_row(..., ARRAY_A).
	 *
	 * @return AiDraft
	 */
	private function hydrate( array $row ): AiDraft {
		return new AiDraft(
			(int) $row['id'],
			(string) $row['draft_uuid'],
			(int) $row['conversation_id'],
			(string) $row['status'],
			(string) $row['provider'],
			(string) $row['model'],
			(string) $row['prompt_policy_version'],
			null !== $row['source_ids_json'] ? (string) $row['source_ids_json'] : null,
			null !== $row['context_fingerprint'] ? (string) $row['context_fingerprint'] : null,
			null !== $row['body_ciphertext'] ? (string) $row['body_ciphertext'] : null,
			null !== $row['failure_class'] ? (string) $row['failure_class'] : null,
			(int) $row['requested_by_user_id'],
			null !== $row['reviewed_by_user_id'] ? (int) $row['reviewed_by_user_id'] : null,
			null !== $row['job_reference'] ? (string) $row['job_reference'] : null,
			null !== $row['lease_token'] ? (string) $row['lease_token'] : null,
			null !== $row['generation_lease_expires_at'] ? (string) $row['generation_lease_expires_at'] : null,
			null !== $row['claimed_at'] ? (string) $row['claimed_at'] : null,
			(int) $row['attempt_count'],
			(string) $row['created_at'],
			null !== $row['generated_at'] ? (string) $row['generated_at'] : null,
			null !== $row['reviewed_at'] ? (string) $row['reviewed_at'] : null,
			(string) $row['updated_at']
		);
	}
}
