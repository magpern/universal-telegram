<?php
/**
 * Operational-summary AI draft persistence.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Automations\Intelligence;

use UniversalTelegram\Core\Security\CredentialResult;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Persistence\SchemaHealth;

/**
 * CredentialVault-backed CRUD for universal_telegram_operational_summary_ai_drafts
 * (docs/plans/m11b-digests-and-operational-intelligence-plan-v1.md §2.6/§3/§4).
 * Referenced only by the fixed five-class allow-list: SummaryAiRequestHandler,
 * SummaryAiGenerationHandler, SummaryAiLeaseSweep, this class itself, and
 * Administration\Automations\IntelligencePanel — enforced by
 * StructuralBoundariesTest (WP7), never by convention alone.
 * UNIQUE(summary_run_id) is the entire per-summary idempotency mechanism —
 * a database constraint, not an application-level row lock.
 */
final class SummaryAiRepository {

	private const CONTEXT_PREFIX = 'ai.summary.body:';

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
	 * Requests a draft for the given summary row: inserts a new `queued`
	 * row, or — if one already exists for this summary_run_id, in any
	 * status including `discarded` — returns the existing row unchanged.
	 * The database's own UNIQUE(summary_run_id) constraint is what makes a
	 * second insert impossible; this method simply catches that condition
	 * and re-reads rather than locking anything itself.
	 *
	 * @param int    $summary_run_id        The owning operational_summary_runs row.
	 * @param int    $requested_by_user_id  The requesting operator.
	 * @param string $provider              Traceability copy at request time.
	 * @param string $model                 Traceability copy at request time.
	 * @param string $prompt_policy_version The prompt policy version in effect.
	 *
	 * @return array{outcome: string, draft_uuid: ?string} outcome is one of 'created', 'existing', 'not_available'.
	 */
	public function request( int $summary_run_id, int $requested_by_user_id, string $provider, string $model, string $prompt_policy_version ): array {
		if ( ! $this->schema_health->is_available() ) {
			return array(
				'outcome'    => 'not_available',
				'draft_uuid' => null,
			);
		}

		global $wpdb;

		$table      = $wpdb->prefix . Migrator::OPERATIONAL_SUMMARY_AI_DRAFTS_TABLE;
		$draft_uuid = wp_generate_uuid4();
		$now        = current_time( 'mysql', true );

		$inserted = $wpdb->insert(
			$table,
			array(
				'summary_run_id'        => $summary_run_id,
				'draft_uuid'            => $draft_uuid,
				'status'                => 'queued',
				'provider'              => $provider,
				'model'                 => $model,
				'prompt_policy_version' => $prompt_policy_version,
				'requested_by_user_id'  => $requested_by_user_id,
				'attempt_count'         => 0,
				'created_at'            => $now,
				'updated_at'            => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
		);

		if ( false !== $inserted ) {
			return array(
				'outcome'    => 'created',
				'draft_uuid' => $draft_uuid,
			);
		}

		// The UNIQUE(summary_run_id) constraint rejected the insert — a
		// draft already exists for this summary; return it as-is, whatever
		// its current status (including discarded — no second generation).
		$existing = $this->find_by_summary_run_id( $summary_run_id );

		return array(
			'outcome'    => 'existing',
			'draft_uuid' => null !== $existing ? $existing->draft_uuid() : null,
		);
	}

	/**
	 * The draft for a given summary row, if any.
	 *
	 * @param int $summary_run_id The owning operational_summary_runs row.
	 *
	 * @return SummaryAiDraft|null
	 */
	public function find_by_summary_run_id( int $summary_run_id ): ?SummaryAiDraft {
		if ( ! $this->schema_health->is_available() ) {
			return null;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::OPERATIONAL_SUMMARY_AI_DRAFTS_TABLE;
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE summary_run_id = %d", $summary_run_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return null === $row ? null : $this->hydrate( $row );
	}

	/**
	 * Finds a draft by its opaque queue/reference identifier.
	 *
	 * @param string $draft_uuid The opaque queue/reference identifier.
	 *
	 * @return SummaryAiDraft|null
	 */
	public function find_by_uuid( string $draft_uuid ): ?SummaryAiDraft {
		if ( ! $this->schema_health->is_available() ) {
			return null;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::OPERATIONAL_SUMMARY_AI_DRAFTS_TABLE;
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE draft_uuid = %s", $draft_uuid ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return null === $row ? null : $this->hydrate( $row );
	}

	/**
	 * Finds a draft by primary key.
	 *
	 * @param int $id Primary key.
	 *
	 * @return SummaryAiDraft|null
	 */
	public function find( int $id ): ?SummaryAiDraft {
		if ( ! $this->schema_health->is_available() ) {
			return null;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::OPERATIONAL_SUMMARY_AI_DRAFTS_TABLE;
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return null === $row ? null : $this->hydrate( $row );
	}

	/**
	 * The site-wide count of rows currently `generating` with an unexpired
	 * lease — supplied to AI\Provider\ProviderConcurrencyGate as this
	 * domain's own active-count callable (§3).
	 *
	 * @return int
	 */
	public function count_active_generating(): int {
		if ( ! $this->schema_health->is_available() ) {
			return 0;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::OPERATIONAL_SUMMARY_AI_DRAFTS_TABLE;
		$now   = current_time( 'mysql', true );

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE status = 'generating' AND generation_lease_expires_at > %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$now
			)
		);
	}

	/**
	 * Claims a `queued` (or expired-lease `generating`) draft for
	 * generation, without opening its own transaction or taking any lock —
	 * invoked by AI\Provider\ProviderConcurrencyGate only after its own
	 * admission check has already passed (§3).
	 *
	 * @param string $draft_uuid    The draft to claim.
	 * @param int    $lease_seconds The lease duration.
	 *
	 * @return array{draft_id: int, lease_token: string, attempt_count: int}|null
	 */
	public function claim_candidate_row( string $draft_uuid, int $lease_seconds ): ?array {
		if ( ! $this->schema_health->is_available() ) {
			return null;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::OPERATIONAL_SUMMARY_AI_DRAFTS_TABLE;
		$now   = current_time( 'mysql', true );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, attempt_count FROM {$table} WHERE draft_uuid = %s AND (status = 'queued' OR (status = 'generating' AND generation_lease_expires_at <= %s)) FOR UPDATE", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
				"UPDATE {$table} SET status = 'generating', lease_token = %s, generation_lease_expires_at = %s, attempt_count = %d, updated_at = %s WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$lease_token,
				$lease_expiry,
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
	 * still matches may complete the row.
	 *
	 * @param int    $draft_id       Primary key.
	 * @param string $draft_uuid     The owning draft's opaque identifier, for the encryption context binding.
	 * @param string $lease_token    This claim's lease token.
	 * @param string $plaintext_body The generated summary text, encrypted here.
	 *
	 * @return bool
	 */
	public function complete_generation( int $draft_id, string $draft_uuid, string $lease_token, string $plaintext_body ): bool {
		if ( ! $this->schema_health->is_available() ) {
			return false;
		}

		$body_ciphertext = $this->credential_vault->encrypt( $plaintext_body, self::CONTEXT_PREFIX . $draft_uuid );

		global $wpdb;

		$table   = $wpdb->prefix . Migrator::OPERATIONAL_SUMMARY_AI_DRAFTS_TABLE;
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'generated', body_ciphertext = %s, generated_at = %s, lease_token = NULL, generation_lease_expires_at = NULL, updated_at = %s WHERE id = %d AND lease_token = %s AND status = 'generating'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$body_ciphertext,
				current_time( 'mysql', true ),
				current_time( 'mysql', true ),
				$draft_id,
				$lease_token
			)
		);

		return false !== $updated && $updated > 0;
	}

	/**
	 * Compare-and-set terminal failure: dead-letters the row and clears its
	 * lease. When $lease_token is null, matches by status alone.
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

		$table = $wpdb->prefix . Migrator::OPERATIONAL_SUMMARY_AI_DRAFTS_TABLE;
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
	 * `queued`.
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

		$table   = $wpdb->prefix . Migrator::OPERATIONAL_SUMMARY_AI_DRAFTS_TABLE;
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
	 * Every `generating` row whose lease has expired — the stale-lease
	 * sweep's own candidate scan.
	 *
	 * @param int $limit Bounded candidate-scan size.
	 *
	 * @return array<int, SummaryAiDraft>
	 */
	public function find_stale_generating( int $limit = 50 ): array {
		if ( ! $this->schema_health->is_available() ) {
			return array();
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::OPERATIONAL_SUMMARY_AI_DRAFTS_TABLE;
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
	 * Atomically re-arms one stale row back to `queued`, clearing its lease.
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

		$table   = $wpdb->prefix . Migrator::OPERATIONAL_SUMMARY_AI_DRAFTS_TABLE;
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
	 * already exhausted.
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

		$table   = $wpdb->prefix . Migrator::OPERATIONAL_SUMMARY_AI_DRAFTS_TABLE;
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
				'reviewed_by_user_id' => (string) $reviewed_by_user_id,
			)
		);
	}

	/**
	 * Marks a `generated`/`reviewed` draft `discarded`.
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
				'reviewed_by_user_id' => (string) $reviewed_by_user_id,
			)
		);
	}

	/**
	 * Decrypts a draft's body, for the operator review UI only.
	 *
	 * @param SummaryAiDraft $draft The draft to decrypt.
	 *
	 * @return CredentialResult|null Null if no body is stored yet.
	 */
	public function decrypt_body( SummaryAiDraft $draft ): ?CredentialResult {
		if ( null === $draft->body_ciphertext() ) {
			return null;
		}

		return $this->credential_vault->decrypt( $draft->body_ciphertext(), self::CONTEXT_PREFIX . $draft->draft_uuid() );
	}

	/**
	 * Anonymizes an operator's identity on every draft they requested or
	 * reviewed — draft content is untouched, and the owning summary row is
	 * never touched by this path at all (§4).
	 *
	 * @param int $operator_user_id The deleted operator's former WordPress user id.
	 */
	public function anonymize_operator( int $operator_user_id ): void {
		if ( ! $this->schema_health->is_available() ) {
			return;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::OPERATIONAL_SUMMARY_AI_DRAFTS_TABLE;

		$wpdb->update( $table, array( 'requested_by_user_id' => null ), array( 'requested_by_user_id' => $operator_user_id ), array( '%s' ), array( '%d' ) );
		$wpdb->update( $table, array( 'reviewed_by_user_id' => null ), array( 'reviewed_by_user_id' => $operator_user_id ), array( '%s' ), array( '%d' ) );
	}

	/**
	 * A compare-and-set transition: succeeds only if the row is currently
	 * in one of the expected statuses.
	 *
	 * @param int                  $id     Primary key.
	 * @param array<int, string>   $from   Expected current statuses.
	 * @param array<string, mixed> $fields Fields to set, including the new status.
	 *
	 * @return bool
	 */
	private function transition_from( int $id, array $from, array $fields ): bool {
		if ( ! $this->schema_health->is_available() ) {
			return false;
		}

		global $wpdb;

		$table        = $wpdb->prefix . Migrator::OPERATIONAL_SUMMARY_AI_DRAFTS_TABLE;
		$placeholders = implode( ',', array_fill( 0, count( $from ), '%s' ) );

		$set_clauses = array();
		$set_values  = array();
		foreach ( $fields as $column => $value ) {
			$set_clauses[] = "{$column} = %s";
			$set_values[]  = $value;
		}
		$set_clauses[] = 'updated_at = %s';
		$set_values[]  = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- column names come only from this file's own fixed internal call sites, never user input.
		$sql = "UPDATE {$table} SET " . implode( ', ', $set_clauses ) . " WHERE id = %d AND status IN ({$placeholders})";

		$updated = $wpdb->query(
			$wpdb->prepare( $sql, array_merge( $set_values, array( $id ), $from ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);

		return false !== $updated && $updated > 0;
	}

	/**
	 * Builds a value object from a raw database row.
	 *
	 * @param array<string, mixed> $row Raw row from $wpdb->get_row(..., ARRAY_A).
	 *
	 * @return SummaryAiDraft
	 */
	private function hydrate( array $row ): SummaryAiDraft {
		return new SummaryAiDraft(
			(int) $row['id'],
			(string) $row['draft_uuid'],
			(int) $row['summary_run_id'],
			(string) $row['status'],
			(string) $row['provider'],
			(string) $row['model'],
			(string) $row['prompt_policy_version'],
			null !== $row['body_ciphertext'] ? (string) $row['body_ciphertext'] : null,
			null !== $row['failure_class'] ? (string) $row['failure_class'] : null,
			null !== $row['requested_by_user_id'] ? (int) $row['requested_by_user_id'] : null,
			null !== $row['reviewed_by_user_id'] ? (int) $row['reviewed_by_user_id'] : null,
			null !== $row['lease_token'] ? (string) $row['lease_token'] : null,
			null !== $row['generation_lease_expires_at'] ? (string) $row['generation_lease_expires_at'] : null,
			(int) $row['attempt_count'],
			(string) $row['created_at'],
			null !== $row['generated_at'] ? (string) $row['generated_at'] : null,
			(string) $row['updated_at']
		);
	}
}
