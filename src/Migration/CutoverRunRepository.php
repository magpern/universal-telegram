<?php
/**
 * SC-M03 final-cutover run persistence.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Migration;

use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Persistence\SchemaHealth;

/**
 * CRUD for `cutover_runs`, its append-only `cutover_transitions` audit
 * trail, and the per-candidate `cutover_activation_audit` trail
 * (docs/adr/0042 §1–§2). Only one run may be "open" (`is_open()`) at a
 * time — enforced here at the application level by `find_open()`, not by a
 * database constraint, since the set of concurrently open runs is always
 * small and the check is cheap.
 */
final class CutoverRunRepository {

	/**
	 * Constructor.
	 *
	 * @param SchemaHealth $schema_health Checked before every operation.
	 */
	public function __construct( private readonly SchemaHealth $schema_health ) {}

	/**
	 * The single currently-open run (`prepared`, `activating`, `activated`
	 * — every state `CutoverState::is_open()` reports true for), if any.
	 * `begin()` must refuse to create a second run while one already
	 * exists — docs/adr/0042 §1's "only one run may be active at a time".
	 * `activated` is deliberately included: a run only stops being open
	 * once it reaches a genuinely terminal state (`complete` or
	 * `activation_failed`), not merely once its bindings are activated —
	 * `confirm-complete` still has to run.
	 */
	public function find_open(): ?CutoverRun {
		if ( ! $this->schema_health->is_available() ) {
			return null;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::CUTOVER_RUNS_TABLE;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE state IN (%s, %s, %s) ORDER BY id DESC LIMIT 1",
				CutoverState::PREPARED->value,
				CutoverState::ACTIVATING->value,
				CutoverState::ACTIVATED->value
			),
			ARRAY_A
		);

		return null === $row ? null : $this->hydrate( $row );
	}

	/**
	 * Finds a run by its `run_uuid` (`cutover_run_id`).
	 *
	 * @param string $run_uuid The run's own uuid.
	 */
	public function find_by_uuid( string $run_uuid ): ?CutoverRun {
		if ( ! $this->schema_health->is_available() ) {
			return null;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::CUTOVER_RUNS_TABLE;

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE run_uuid = %s", $run_uuid ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return null === $row ? null : $this->hydrate( $row );
	}

	/**
	 * Creates a new run in `prepared` state. Refuses (returns null) if a
	 * run is already open — callers must check `find_open()` first, but
	 * this method re-checks defensively rather than trusting the caller.
	 *
	 * @param int $cohort_count Number of candidates in the cohort about to be preflighted.
	 */
	public function create_prepared( int $cohort_count ): ?CutoverRun {
		if ( ! $this->schema_health->is_available() || null !== $this->find_open() ) {
			return null;
		}

		global $wpdb;

		$table    = $wpdb->prefix . Migrator::CUTOVER_RUNS_TABLE;
		$run_uuid = wp_generate_uuid4();
		$now      = current_time( 'mysql', true );

		$ok = $wpdb->insert(
			$table,
			array(
				'run_uuid'            => $run_uuid,
				'state'               => CutoverState::PREPARED->value,
				'cohort_count'        => $cohort_count,
				'entered_prepared_at' => $now,
				'created_at'          => $now,
				'updated_at'          => $now,
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		if ( false === $ok ) {
			return null;
		}

		$run = $this->find_by_uuid( $run_uuid );

		if ( null !== $run ) {
			$this->record_transition( $run->id(), 'not_started', CutoverState::PREPARED->value, null, 'wp-cli', sprintf( 'cohort_count=%d', $cohort_count ) );
		}

		return $run;
	}

	/**
	 * `prepared → activating`. CAS-guarded on the run's own id and current
	 * state. Idempotent: if already `activating`, reports success without a
	 * second CAS (resume-after-crash, docs/adr/0042 §1).
	 *
	 * @param int      $run_id        The run's primary key.
	 * @param int|null $requested_by  The WP-CLI-authenticated OS user, if known.
	 * @param string   $requested_via 'wp-cli' for every caller.
	 */
	public function transition_to_activating( int $run_id, ?int $requested_by, string $requested_via = 'wp-cli' ): bool {
		return $this->cas_transition( $run_id, CutoverState::PREPARED, CutoverState::ACTIVATING, 'entered_activating_at', $requested_by, $requested_via, null );
	}

	/**
	 * `activating → activated`. CAS-guarded.
	 *
	 * @param int      $run_id        The run's primary key.
	 * @param int|null $requested_by  The WP-CLI-authenticated OS user, if known.
	 * @param string   $requested_via 'wp-cli' for every caller.
	 * @param string   $detail        Non-content summary, e.g. "activated=5".
	 */
	public function transition_to_activated( int $run_id, ?int $requested_by, string $requested_via, string $detail ): bool {
		return $this->cas_transition( $run_id, CutoverState::ACTIVATING, CutoverState::ACTIVATED, 'activated_at', $requested_by, $requested_via, $detail );
	}

	/**
	 * `activating → activation_failed`. CAS-guarded — recorded after every
	 * committed candidate in the same run has already been compensated back
	 * to `prepared` (docs/adr/0042 §2).
	 *
	 * @param int      $run_id        The run's primary key.
	 * @param int|null $requested_by  The WP-CLI-authenticated OS user, if known.
	 * @param string   $requested_via 'wp-cli' for every caller.
	 * @param string   $detail        Non-content summary, e.g. "failed_candidate=<uuid> compensated=3".
	 */
	public function transition_to_activation_failed( int $run_id, ?int $requested_by, string $requested_via, string $detail ): bool {
		return $this->cas_transition( $run_id, CutoverState::ACTIVATING, CutoverState::ACTIVATION_FAILED, 'activation_failed_at', $requested_by, $requested_via, $detail );
	}

	/**
	 * `activated → complete`. CAS-guarded.
	 *
	 * @param int      $run_id        The run's primary key.
	 * @param int|null $requested_by  The WP-CLI-authenticated OS user, if known.
	 * @param string   $requested_via 'wp-cli' for every caller.
	 */
	public function transition_to_complete( int $run_id, ?int $requested_by, string $requested_via ): bool {
		return $this->cas_transition( $run_id, CutoverState::ACTIVATED, CutoverState::COMPLETE, 'completed_at', $requested_by, $requested_via, null );
	}

	/**
	 * Records one per-candidate activation or compensation action, run-
	 * correlated (docs/adr/0042 §2). `cutover_run_id` is `$run_id` here (the
	 * run's own primary key doubles as the correlation id within this
	 * repository's own tables; `run_uuid` is the value surfaced externally
	 * in CLI output/other audit systems).
	 *
	 * @param int    $run_id       The run's primary key.
	 * @param string $binding_uuid The candidate binding's UUID.
	 * @param string $action       'activate' or 'compensate'.
	 * @param int    $from_cas     The binding's `cas_version` before this action.
	 * @param int    $to_cas       The binding's `cas_version` after this action.
	 */
	public function record_activation_audit( int $run_id, string $binding_uuid, string $action, int $from_cas, int $to_cas ): void {
		if ( ! $this->schema_health->is_available() ) {
			return;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::CUTOVER_ACTIVATION_AUDIT_TABLE;

		$wpdb->insert(
			$table,
			array(
				'run_id'       => $run_id,
				'binding_uuid' => $binding_uuid,
				'action'       => $action,
				'from_cas'     => $from_cas,
				'to_cas'       => $to_cas,
				'occurred_at'  => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%d', '%d', '%s' )
		);
	}

	/**
	 * Every activation-audit row for a run, in insertion order — used by
	 * resume-after-crash to reconstruct which candidates already committed.
	 *
	 * @param int $run_id The run's primary key.
	 *
	 * @return array<int, array{binding_uuid: string, action: string, from_cas: int, to_cas: int}>
	 */
	public function activation_audit_for_run( int $run_id ): array {
		if ( ! $this->schema_health->is_available() ) {
			return array();
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::CUTOVER_ACTIVATION_AUDIT_TABLE;

		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT binding_uuid, action, from_cas, to_cas FROM {$table} WHERE run_id = %d ORDER BY id ASC", $run_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		$rows = is_array( $rows ) ? $rows : array();

		return array_map(
			static fn( array $row ): array => array(
				'binding_uuid' => (string) $row['binding_uuid'],
				'action'       => (string) $row['action'],
				'from_cas'     => (int) $row['from_cas'],
				'to_cas'       => (int) $row['to_cas'],
			),
			$rows
		);
	}

	/**
	 * One CAS `UPDATE ... WHERE id = %d AND state = %s`, mirroring
	 * `QuiescenceGate::try_transition()`'s identical mechanic, plus its
	 * matching audit-trail insert.
	 *
	 * @param int          $run_id            The run's primary key.
	 * @param CutoverState $from_state        The state transitioned from.
	 * @param CutoverState $to_state          The state transitioned to.
	 * @param string       $entered_at_column The Table column stamped with "now".
	 * @param int|null     $requested_by      The WP-CLI-authenticated OS user, if known.
	 * @param string       $requested_via     'wp-cli' for every caller.
	 * @param string|null  $detail            Optional non-content audit detail.
	 */
	private function cas_transition( int $run_id, CutoverState $from_state, CutoverState $to_state, string $entered_at_column, ?int $requested_by, string $requested_via, ?string $detail ): bool {
		if ( ! $this->schema_health->is_available() ) {
			return false;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::CUTOVER_RUNS_TABLE;
		$now   = current_time( 'mysql', true );

		$allowed_columns = array( 'entered_activating_at', 'activated_at', 'activation_failed_at', 'completed_at' );

		if ( ! in_array( $entered_at_column, $allowed_columns, true ) ) {
			return false;
		}

		$updated = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE {$table} SET state = %s, {$entered_at_column} = %s, updated_at = %s WHERE id = %d AND state = %s",
				$to_state->value,
				$now,
				$now,
				$run_id,
				$from_state->value
			)
		);

		if ( 1 !== $updated ) {
			return false;
		}

		$this->record_transition( $run_id, $from_state->value, $to_state->value, $requested_by, $requested_via, $detail );

		return true;
	}

	/**
	 * Inserts one append-only audit row.
	 *
	 * @param int         $run_id        The run's primary key.
	 * @param string      $from_state    The state transitioned from.
	 * @param string      $to_state      The state transitioned to.
	 * @param int|null    $requested_by  The WP-CLI-authenticated OS user, if known.
	 * @param string      $requested_via 'wp-cli' for every caller.
	 * @param string|null $detail        Optional non-content audit detail.
	 */
	private function record_transition( int $run_id, string $from_state, string $to_state, ?int $requested_by, string $requested_via, ?string $detail = null ): void {
		global $wpdb;

		$table = $wpdb->prefix . Migrator::CUTOVER_TRANSITIONS_TABLE;

		$wpdb->insert(
			$table,
			array(
				'run_id'        => $run_id,
				'from_state'    => $from_state,
				'to_state'      => $to_state,
				'requested_by'  => $requested_by,
				'requested_via' => $requested_via,
				'detail'        => $detail,
				'occurred_at'   => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * Hydrates a run value object.
	 *
	 * @param array<string, mixed> $row Database row.
	 */
	private function hydrate( array $row ): CutoverRun {
		return new CutoverRun(
			(int) $row['id'],
			(string) $row['run_uuid'],
			CutoverState::from( (string) $row['state'] ),
			(int) $row['cohort_count'],
			(string) $row['created_at'],
			(string) $row['updated_at']
		);
	}
}
