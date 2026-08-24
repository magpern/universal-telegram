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
				'draft_uuid'            => $draft_uuid,
				'conversation_id'       => $conversation_id,
				'status'                => 'queued',
				'provider'              => $provider,
				'model'                 => $model,
				'prompt_policy_version' => $prompt_policy_version,
				'requested_by_user_id'  => $requested_by_user_id,
				'attempt_count'         => 0,
				'created_at'            => $now,
				'updated_at'            => $now,
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return null;
		}

		return $this->find( (int) $wpdb->insert_id );
	}

	/**
	 * Finds a draft by primary key.
	 *
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
	 * Finds a draft by its opaque queue/reference identifier.
	 *
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
	 * The site-wide count of drafts currently in a given status — the
	 * Diagnostics tab's own AI panel (Administration\AI\AIDiagnosticsPanel),
	 * never draft content itself.
	 *
	 * @param string $status One of the fixed lifecycle states.
	 *
	 * @return int
	 */
	public function count_by_status( string $status ): int {
		if ( ! $this->schema_health->is_available() ) {
			return 0;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::AI_DRAFTS_TABLE;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE status = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$status
			)
		);
	}

	/**
	 * Anonymizes an operator's identity on every draft they requested or
	 * reviewed — draft content is untouched (docs/adr/0028 §4 retention
	 * table, mirroring ConversationNote's identical note-anonymization
	 * precedent, ADR-0026 decision 12b).
	 *
	 * @param int $operator_user_id The deleted operator's former WordPress user id.
	 */
	public function anonymize_operator( int $operator_user_id ): void {
		if ( ! $this->schema_health->is_available() ) {
			return;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::AI_DRAFTS_TABLE;

		$wpdb->update( $table, array( 'requested_by_user_id' => null ), array( 'requested_by_user_id' => $operator_user_id ), array( '%s' ), array( '%d' ) );
		$wpdb->update( $table, array( 'reviewed_by_user_id' => null ), array( 'reviewed_by_user_id' => $operator_user_id ), array( '%s' ), array( '%d' ) );
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
	 * The single, transactional entry point for an operator's draft
	 * request (docs/adr/0028 decision 5, §3.1-A/§3.2 of the frozen plan).
	 * Locks the owning, already-existing conversation row for the duration
	 * of one explicit transaction — this is also the sole idempotency
	 * mechanism for a duplicate/rapid-double-click request, and what makes
	 * "at most one active draft per conversation" race-safe. Enforces, in
	 * order: an already-active draft is returned as-is (idempotent, no new
	 * row); a retained (`generated`/`reviewed`/`approved`) draft rejects
	 * the request outright; a `failed` draft within the last
	 * $cooldown_seconds rejects the request; otherwise a fresh `queued`
	 * row is inserted.
	 *
	 * @param int    $conversation_id       The owning conversation (must already exist).
	 * @param int    $requested_by_user_id  The requesting operator.
	 * @param string $provider              Traceability copy at request time.
	 * @param string $model                 Traceability copy at request time.
	 * @param string $prompt_policy_version The prompt policy version in effect.
	 * @param int    $cooldown_seconds      The post-failure cooldown window.
	 *
	 * @return array{outcome: string, draft_uuid: ?string, cooldown_remaining_seconds: ?int}
	 *               outcome is one of 'created', 'existing_active', 'rejected_retained', 'rejected_cooldown', 'not_found'.
	 */
	public function request_draft(
		int $conversation_id,
		int $requested_by_user_id,
		string $provider,
		string $model,
		string $prompt_policy_version,
		int $cooldown_seconds
	): array {
		$not_found = array(
			'outcome'                    => 'not_found',
			'draft_uuid'                 => null,
			'cooldown_remaining_seconds' => null,
		);

		if ( ! $this->schema_health->is_available() ) {
			return $not_found;
		}

		global $wpdb;

		$conversations_table = $wpdb->prefix . Migrator::CONVERSATIONS_TABLE;
		$drafts_table        = $wpdb->prefix . Migrator::AI_DRAFTS_TABLE;
		$now                 = current_time( 'mysql', true );

		$wpdb->query( 'START TRANSACTION' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$locked = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$conversations_table} WHERE id = %d FOR UPDATE", $conversation_id ) );

		if ( null === $locked ) {
			$wpdb->query( 'ROLLBACK' );
			return $not_found;
		}

		$active = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT draft_uuid FROM {$drafts_table} WHERE conversation_id = %d AND status IN ('queued','generating') LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$conversation_id
			),
			ARRAY_A
		);

		if ( null !== $active ) {
			$wpdb->query( 'COMMIT' );
			return array(
				'outcome'                    => 'existing_active',
				'draft_uuid'                 => (string) $active['draft_uuid'],
				'cooldown_remaining_seconds' => null,
			);
		}

		$retained = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$drafts_table} WHERE conversation_id = %d AND status IN ('generated','reviewed','approved') LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$conversation_id
			)
		);

		if ( null !== $retained ) {
			$wpdb->query( 'COMMIT' );
			return array(
				'outcome'                    => 'rejected_retained',
				'draft_uuid'                 => null,
				'cooldown_remaining_seconds' => null,
			);
		}

		$last_failed_at = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT updated_at FROM {$drafts_table} WHERE conversation_id = %d AND status = 'failed' ORDER BY updated_at DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$conversation_id
			)
		);

		if ( null !== $last_failed_at ) {
			$elapsed   = time() - (int) strtotime( $last_failed_at . ' UTC' );
			$remaining = $cooldown_seconds - $elapsed;

			if ( $remaining > 0 ) {
				$wpdb->query( 'COMMIT' );
				return array(
					'outcome'                    => 'rejected_cooldown',
					'draft_uuid'                 => null,
					'cooldown_remaining_seconds' => $remaining,
				);
			}
		}

		$draft_uuid = wp_generate_uuid4();

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$drafts_table} (draft_uuid, conversation_id, status, provider, model, prompt_policy_version, requested_by_user_id, attempt_count, created_at, updated_at) VALUES (%s, %d, 'queued', %s, %s, %s, %d, 0, %s, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$draft_uuid,
				$conversation_id,
				$provider,
				$model,
				$prompt_policy_version,
				$requested_by_user_id,
				$now,
				$now
			)
		);

		$wpdb->query( 'COMMIT' );

		return array(
			'outcome'                    => 'created',
			'draft_uuid'                 => $draft_uuid,
			'cooldown_remaining_seconds' => null,
		);
	}

	/**
	 * Claims a `queued` (or expired-lease `generating`) draft for
	 * generation, under the site-wide concurrency cap (docs/adr/0028
	 * decision 5, §3.1-B of the frozen plan). Locks the always-present,
	 * migration-seeded singleton `universal_telegram_ai_config` row
	 * (`id=1`) as the global admission mutex for the duration of one
	 * explicit transaction — every claim attempt across the whole site
	 * serializes on this single row lock, which is what makes the
	 * count-then-claim sequence race-safe (an aggregate `COUNT(*) FOR
	 * UPDATE` alone does not reliably serialize). This is the one place in
	 * the `AI` boundary that uses an explicit transaction rather than a
	 * single atomic `UPDATE ... WHERE`, because "at most N rows may be
	 * `generating` at once" cannot be expressed as a single-row
	 * compare-and-set.
	 *
	 * @param string $draft_uuid      The draft to claim.
	 * @param int    $lease_seconds   The lease duration.
	 * @param int    $max_concurrent  The site-wide concurrency cap.
	 *
	 * @return array{draft_id: int, lease_token: string, attempt_count: int}|null Null if the row is not currently claimable, or the cap is reached.
	 */
	public function claim_for_generation( string $draft_uuid, int $lease_seconds, int $max_concurrent ): ?array {
		if ( ! $this->schema_health->is_available() ) {
			return null;
		}

		global $wpdb;

		$config_table = $wpdb->prefix . Migrator::AI_CONFIG_TABLE;
		$drafts_table = $wpdb->prefix . Migrator::AI_DRAFTS_TABLE;
		$now          = current_time( 'mysql', true );

		$wpdb->query( 'START TRANSACTION' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$locked = $wpdb->get_var( "SELECT id FROM {$config_table} WHERE id = 1 FOR UPDATE" );

		if ( null === $locked ) {
			$wpdb->query( 'ROLLBACK' );
			return null;
		}

		$active_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$drafts_table} WHERE status = 'generating' AND generation_lease_expires_at > %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$now
			)
		);

		if ( $active_count >= $max_concurrent ) {
			$wpdb->query( 'ROLLBACK' );
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, attempt_count FROM {$drafts_table} WHERE draft_uuid = %s AND (status = 'queued' OR (status = 'generating' AND generation_lease_expires_at <= %s)) FOR UPDATE", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$draft_uuid,
				$now
			),
			ARRAY_A
		);

		if ( null === $row ) {
			$wpdb->query( 'ROLLBACK' );
			return null;
		}

		$lease_token   = wp_generate_uuid4();
		$attempt_count = (int) $row['attempt_count'] + 1;
		$lease_expiry  = gmdate( 'Y-m-d H:i:s', time() + $lease_seconds );

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$drafts_table} SET status = 'generating', lease_token = %s, generation_lease_expires_at = %s, claimed_at = %s, attempt_count = %d, updated_at = %s WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$lease_token,
				$lease_expiry,
				$now,
				$attempt_count,
				$now,
				$row['id']
			)
		);

		$wpdb->query( 'COMMIT' );

		return array(
			'draft_id'      => (int) $row['id'],
			'lease_token'   => $lease_token,
			'attempt_count' => $attempt_count,
		);
	}

	/**
	 * The site-wide count of rows currently `generating` with an unexpired
	 * lease — the same read claim_for_generation() already performs while
	 * holding the config-row lock, extracted as its own method so a shared
	 * cross-feature admission gate (AI\Provider\ProviderConcurrencyGate,
	 * docs/plans/m11b-digests-and-operational-intelligence-plan-v1.md §3)
	 * can sum this count together with a second table's own count, inside
	 * one transaction the gate itself opens. This method takes no lock of
	 * its own — the caller is expected to already hold the config-row lock
	 * (or an equivalent mutex) for the duration of the count-then-claim
	 * sequence, exactly as claim_for_generation() does internally.
	 *
	 * @return int
	 */
	public function count_active_generating(): int {
		if ( ! $this->schema_health->is_available() ) {
			return 0;
		}

		global $wpdb;

		$drafts_table = $wpdb->prefix . Migrator::AI_DRAFTS_TABLE;
		$now          = current_time( 'mysql', true );

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$drafts_table} WHERE status = 'generating' AND generation_lease_expires_at > %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$now
			)
		);
	}

	/**
	 * Claims a `queued` (or expired-lease `generating`) draft for
	 * generation, without opening its own transaction or taking the
	 * config-row lock — the candidate-select-and-claim half of
	 * claim_for_generation()'s own logic, extracted so
	 * AI\Provider\ProviderConcurrencyGate can invoke it after the gate's
	 * own admission check has already passed, inside the same transaction
	 * the gate opened (§3). The caller is responsible for having already
	 * verified admission (count_active_generating() below the cap) under
	 * the same lock/transaction this call executes within.
	 *
	 * @param string $draft_uuid    The draft to claim.
	 * @param int    $lease_seconds The lease duration.
	 *
	 * @return array{draft_id: int, lease_token: string, attempt_count: int}|null Null if the row is not currently claimable.
	 */
	public function claim_candidate_row( string $draft_uuid, int $lease_seconds ): ?array {
		if ( ! $this->schema_health->is_available() ) {
			return null;
		}

		global $wpdb;

		$drafts_table = $wpdb->prefix . Migrator::AI_DRAFTS_TABLE;
		$now          = current_time( 'mysql', true );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, attempt_count FROM {$drafts_table} WHERE draft_uuid = %s AND (status = 'queued' OR (status = 'generating' AND generation_lease_expires_at <= %s)) FOR UPDATE", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$draft_uuid,
				$now
			),
			ARRAY_A
		);

		if ( null === $row ) {
			return null;
		}

		$lease_token   = wp_generate_uuid4();
		$attempt_count = (int) $row['attempt_count'] + 1;
		$lease_expiry  = gmdate( 'Y-m-d H:i:s', time() + $lease_seconds );

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$drafts_table} SET status = 'generating', lease_token = %s, generation_lease_expires_at = %s, claimed_at = %s, attempt_count = %d, updated_at = %s WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$lease_token,
				$lease_expiry,
				$now,
				$attempt_count,
				$now,
				$row['id']
			)
		);

		return array(
			'draft_id'      => (int) $row['id'],
			'lease_token'   => $lease_token,
			'attempt_count' => $attempt_count,
		);
	}

	/**
	 * Compare-and-set success write: only the claimant whose lease_token
	 * still matches may complete the row — a stale, delayed worker's
	 * completion after its lease was reclaimed is silently discarded
	 * (docs/adr/0028 decision 5, §3.3 of the frozen plan).
	 *
	 * @param int    $draft_id              Primary key.
	 * @param string $draft_uuid            The owning draft's opaque identifier, for the encryption context binding.
	 * @param string $lease_token           This claim's lease token.
	 * @param string $plaintext_body        The generated draft text, encrypted here — never passed in already-encrypted.
	 * @param string $source_ids_json       JSON array of sources used.
	 * @param string $context_fingerprint   SHA-256 of the submitted context.
	 * @param string $prompt_policy_version The prompt policy version used.
	 *
	 * @return bool Whether this call's write won (false = stale/superseded claim).
	 */
	public function complete_generation(
		int $draft_id,
		string $draft_uuid,
		string $lease_token,
		string $plaintext_body,
		string $source_ids_json,
		string $context_fingerprint,
		string $prompt_policy_version
	): bool {
		if ( ! $this->schema_health->is_available() ) {
			return false;
		}

		$body_ciphertext = $this->credential_vault->encrypt( $plaintext_body, self::CONTEXT_PREFIX . $draft_uuid );

		global $wpdb;

		$table   = $wpdb->prefix . Migrator::AI_DRAFTS_TABLE;
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'generated', body_ciphertext = %s, source_ids_json = %s, context_fingerprint = %s, prompt_policy_version = %s, generated_at = %s, lease_token = NULL, generation_lease_expires_at = NULL, updated_at = %s WHERE id = %d AND lease_token = %s AND status = 'generating'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$body_ciphertext,
				$source_ids_json,
				$context_fingerprint,
				$prompt_policy_version,
				current_time( 'mysql', true ),
				current_time( 'mysql', true ),
				$draft_id,
				$lease_token
			)
		);

		return false !== $updated && $updated > 0;
	}

	/**
	 * Compare-and-set terminal failure: dead-letters the row and clears
	 * its lease. When $lease_token is null, matches by status alone — used
	 * for a pre-claim failure (e.g. circuit already open) where no lease
	 * was ever taken.
	 *
	 * @param int         $draft_id      Primary key.
	 * @param string|null $lease_token   This claim's lease token, or null for a pre-claim failure.
	 * @param string      $failure_class Fixed taxonomy code.
	 *
	 * @return bool
	 */
	public function fail( int $draft_id, ?string $lease_token, string $failure_class ): bool {
		if ( ! $this->schema_health->is_available() ) {
			return false;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::AI_DRAFTS_TABLE;
		$now   = current_time( 'mysql', true );

		if ( null !== $lease_token ) {
			$updated = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET status = 'failed', failure_class = %s, lease_token = NULL, generation_lease_expires_at = NULL, updated_at = %s WHERE id = %d AND lease_token = %s AND status = 'generating'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$failure_class,
					$now,
					$draft_id,
					$lease_token
				)
			);
		} else {
			$updated = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET status = 'failed', failure_class = %s, lease_token = NULL, generation_lease_expires_at = NULL, updated_at = %s WHERE id = %d AND status IN ('queued','generating')", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$failure_class,
					$now,
					$draft_id
				)
			);
		}

		return false !== $updated && $updated > 0;
	}

	/**
	 * Compare-and-set retryable-failure release: returns the row to
	 * `queued` before the caller rethrows to let WorkerRunner's own
	 * bounded retry sequence run (docs/adr/0028 decision 5, §3.3 of the
	 * frozen plan). attempt_count is left untouched — it is incremented
	 * only at claim time.
	 *
	 * @param int    $draft_id    Primary key.
	 * @param string $lease_token This claim's lease token.
	 *
	 * @return bool
	 */
	public function release_to_queued( int $draft_id, string $lease_token ): bool {
		if ( ! $this->schema_health->is_available() ) {
			return false;
		}

		global $wpdb;

		$table   = $wpdb->prefix . Migrator::AI_DRAFTS_TABLE;
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'queued', lease_token = NULL, generation_lease_expires_at = NULL, updated_at = %s WHERE id = %d AND lease_token = %s AND status = 'generating'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				current_time( 'mysql', true ),
				$draft_id,
				$lease_token
			)
		);

		return false !== $updated && $updated > 0;
	}

	/**
	 * Records the current Action Scheduler action id for a draft — set on
	 * the initial enqueue and overwritten on every retry or sweep-triggered
	 * re-enqueue (docs/adr/0028 decision 4, §3.5 of the frozen plan).
	 * Diagnostics only, never referenced from any queue payload.
	 *
	 * @param int    $draft_id      Primary key.
	 * @param string $job_reference The Action Scheduler action id.
	 *
	 * @return bool
	 */
	public function set_job_reference( int $draft_id, string $job_reference ): bool {
		if ( ! $this->schema_health->is_available() ) {
			return false;
		}

		global $wpdb;

		return false !== $wpdb->update(
			$wpdb->prefix . Migrator::AI_DRAFTS_TABLE,
			array( 'job_reference' => $job_reference ),
			array( 'id' => $draft_id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Every `generating` row whose lease has expired — the stale-lease
	 * sweep's own candidate scan (docs/adr/0028 decision 5, §3.5 of the
	 * frozen plan). A plain, unlocked read: the sweep's own reclaim/exhaust
	 * writes are each an atomic, self-verifying compare-and-set, so a
	 * stale snapshot here can never cause an unsafe write.
	 *
	 * @param int $limit Bounded candidate-scan size.
	 *
	 * @return array<int, AiDraft>
	 */
	public function find_stale_generating( int $limit = 50 ): array {
		if ( ! $this->schema_health->is_available() ) {
			return array();
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::AI_DRAFTS_TABLE;
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = 'generating' AND generation_lease_expires_at < %s ORDER BY id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				current_time( 'mysql', true ),
				$limit
			),
			ARRAY_A
		);

		return array_map( array( $this, 'hydrate' ), null === $rows ? array() : $rows );
	}

	/**
	 * Atomically re-arms one stale row back to `queued`, clearing its
	 * lease — succeeds only if the row is still exactly in the expired
	 * state this call observed (re-verified in the WHERE clause, not
	 * assumed from a prior read), so two overlapping sweep runs can never
	 * both win the same row (docs/adr/0028 decision 5, §3.5).
	 *
	 * @param int $draft_id Primary key.
	 *
	 * @return bool
	 */
	public function try_reclaim_stale( int $draft_id ): bool {
		if ( ! $this->schema_health->is_available() ) {
			return false;
		}

		global $wpdb;

		$table   = $wpdb->prefix . Migrator::AI_DRAFTS_TABLE;
		$now     = current_time( 'mysql', true );
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'queued', lease_token = NULL, generation_lease_expires_at = NULL, updated_at = %s WHERE id = %d AND status = 'generating' AND generation_lease_expires_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$now,
				$draft_id,
				$now
			)
		);

		return false !== $updated && $updated > 0;
	}

	/**
	 * Atomically dead-letters one stale row whose shared attempt budget is
	 * already exhausted — same self-verifying WHERE-clause guarantee as
	 * try_reclaim_stale() (docs/adr/0028 decision 5, §3.5).
	 *
	 * @param int $draft_id Primary key.
	 *
	 * @return bool
	 */
	public function try_exhaust_stale( int $draft_id ): bool {
		if ( ! $this->schema_health->is_available() ) {
			return false;
		}

		global $wpdb;

		$table   = $wpdb->prefix . Migrator::AI_DRAFTS_TABLE;
		$now     = current_time( 'mysql', true );
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'failed', failure_class = 'crashed_exhausted', lease_token = NULL, generation_lease_expires_at = NULL, updated_at = %s WHERE id = %d AND status = 'generating' AND generation_lease_expires_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$now,
				$draft_id,
				$now
			)
		);

		return false !== $updated && $updated > 0;
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

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- column names come only from this file's own fixed internal call sites, never user input; placeholders are filled by the immediately following prepare() call.
		$sql = "UPDATE {$table} SET " . implode( ', ', $set_clauses ) . " WHERE id = %d AND status IN ({$placeholders})";

		$updated = $wpdb->query(
			$wpdb->prepare( $sql, array_merge( $set_values, array( $id ), $from ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is this method's own fixed-shape format string (see comment above), never user input.
		);

		return false !== $updated && $updated > 0;
	}

	/**
	 * Finds the single draft for a conversation matching any of the given statuses.
	 *
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
			null !== $row['requested_by_user_id'] ? (int) $row['requested_by_user_id'] : null,
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
