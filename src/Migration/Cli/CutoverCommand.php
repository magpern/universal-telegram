<?php
/**
 * WP-CLI SC-M03 final-cutover operator surface.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Migration\Cli;

use UniversalTelegram\Migration\CutoverActivationService;
use UniversalTelegram\Migration\CutoverRunRepository;
use UniversalTelegram\Migration\CutoverState;
use UniversalTelegram\Migration\DeferredUpdateRepository;
use UniversalTelegram\Migration\QuiescenceGate;
use UniversalTelegram\Migration\QuiescenceState;

/**
 * `wp universal-telegram cutover <status|begin|activate|confirm-complete|incident-acknowledge|recover>`
 * (docs/adr/0042 §1). WP-CLI-only, the same OS-shell authority boundary
 * every prior migration surface in this repository already uses.
 *
 * There is deliberately no `handoff-deferred-updates` action here — the
 * cohort-aware handoff dispatch is folded into the existing, amended
 * `wp universal-telegram quiescence replay-deferred-updates` command
 * (`QuiescenceCommand`), which is the single authoritative drain
 * (docs/adr/0042 §3); a separate command here would reintroduce the exact
 * scan-then-separately-drain race this design closes.
 */
final class CutoverCommand {

	/**
	 * Constructor.
	 *
	 * @param CutoverRunRepository     $runs       Run state, transitions, activation audit.
	 * @param CutoverActivationService $activation Preflight and the commit/compensation saga.
	 * @param QuiescenceGate           $quiescence Quiescence state, required `quiescent` before `begin`.
	 * @param DeferredUpdateRepository $deferred   Incident lookup/resolution for `incident-acknowledge`.
	 */
	public function __construct(
		private readonly CutoverRunRepository $runs,
		private readonly CutoverActivationService $activation,
		private readonly QuiescenceGate $quiescence,
		private readonly DeferredUpdateRepository $deferred
	) {}

	/**
	 * Registers the WP-CLI command when WP_CLI is present.
	 */
	public function register(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( '\WP_CLI' ) ) {
			return;
		}

		\WP_CLI::add_command( 'universal-telegram cutover', array( $this, 'dispatch' ) );
	}

	/**
	 * WP-CLI dispatcher.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : status|begin|activate|confirm-complete|incident-acknowledge|recover
	 *
	 * [--cohort-file=<path>]
	 * : Required by begin. One support_conversation_uuid per line.
	 *
	 * [--assume-cutover-authority]
	 * : Required by activate and confirm-complete and incident-acknowledge. Operator confirmation only, not authentication.
	 *
	 * [--run=<run_uuid>]
	 * : Required by activate and confirm-complete.
	 *
	 * [--id=<deferred_row_id>]
	 * : Required by incident-acknowledge.
	 *
	 * [--po-decision-ref=<opaque-reference>]
	 * : Required by incident-acknowledge. An opaque, pre-existing Product Owner decision reference only — never free-form content.
	 *
	 * @param array<int, string>   $args       Positional args.
	 * @param array<string, mixed> $assoc_args Flags.
	 */
	public function dispatch( array $args, array $assoc_args ): void {
		$action = $args[0] ?? '';

		switch ( $action ) {
			case 'status':
				$this->status();
				break;
			case 'begin':
				$this->begin( $assoc_args );
				break;
			case 'activate':
				$this->activate( $assoc_args );
				break;
			case 'confirm-complete':
				$this->confirm_complete( $assoc_args );
				break;
			case 'incident-acknowledge':
				$this->incident_acknowledge( $assoc_args );
				break;
			case 'recover':
				$this->recover();
				break;
			default:
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error -- WP-CLI path only.
				\WP_CLI::error( 'Usage: wp universal-telegram cutover <status|begin|activate|confirm-complete|incident-acknowledge|recover>' ); // @phpstan-ignore class.notFound
		}
	}

	/**
	 * `status`: read-only. Never surfaces binding/message content.
	 */
	private function status(): void {
		$run = $this->runs->find_open();

		if ( null === $run ) {
			\WP_CLI::log( 'No open cutover run.' ); // @phpstan-ignore class.notFound
		} else {
			\WP_CLI::log( sprintf( 'Open run: %s state=%s cohort_count=%d', $run->run_uuid(), $run->state()->value, $run->cohort_count() ) ); // @phpstan-ignore class.notFound
		}

		\WP_CLI::log( 'Quiescence state: ' . $this->quiescence->state()->value ); // @phpstan-ignore class.notFound
		\WP_CLI::log( sprintf( 'Unresolved deferred-update backlog (blocks replaying → idle / confirm-complete): %d', $this->deferred->unresolved_backlog_count() ) ); // @phpstan-ignore class.notFound
	}

	/**
	 * `begin --cohort-file=<path>`: read-only whole-cohort preflight
	 * (docs/adr/0042 §2). Requires quiescence.state === quiescent. Writes
	 * only the new run's own row (state `prepared`) — no binding is
	 * touched.
	 *
	 * @param array<string, mixed> $assoc_args Flags.
	 */
	private function begin( array $assoc_args ): void {
		$path = isset( $assoc_args['cohort-file'] ) ? (string) $assoc_args['cohort-file'] : '';

		if ( '' === $path || ! is_readable( $path ) ) {
			\WP_CLI::error( '--cohort-file=<path> is required and must be readable.' ); // @phpstan-ignore class.notFound
			return;
		}

		if ( QuiescenceState::QUIESCENT !== $this->quiescence->state() ) {
			\WP_CLI::error( 'Quiescence must be in state "quiescent" before `cutover begin`.' ); // @phpstan-ignore class.notFound
			return;
		}

		if ( null !== $this->runs->find_open() ) {
			\WP_CLI::error( 'A cutover run is already open — only one run may be active at a time.' ); // @phpstan-ignore class.notFound
			return;
		}

		// Global preflight gate (docs/adr/0042 §2's "free of blocking
		// incidents"): refuse to begin a new run while any prior-run UT
		// incident remains unresolved — see CutoverActivationService's own
		// docblock for why this is a global, not per-candidate, check.
		if ( $this->deferred->unresolved_backlog_count() > 0 ) {
			\WP_CLI::error( 'Unresolved deferred-update incidents exist — resolve them (retry or incident-acknowledge) before beginning a new cutover run.' ); // @phpstan-ignore class.notFound
			return;
		}

		$lines = array_values( array_filter( array_map( 'trim', (array) file( $path ) ) ) );

		if ( array() === $lines ) {
			\WP_CLI::error( 'Cohort file is empty.' ); // @phpstan-ignore class.notFound
			return;
		}

		$preflight = $this->activation->preflight( $lines );

		foreach ( $preflight['results'] as $result ) {
			$status = $result['eligible'] ? 'eligible' : ( 'ineligible: ' . $result['reason'] );
			\WP_CLI::log( sprintf( '  %s — %s', $result['conversation_uuid'], $status ) ); // @phpstan-ignore class.notFound
		}

		if ( ! $preflight['eligible'] ) {
			\WP_CLI::error( 'Cohort preflight failed — the whole cohort is refused; no run was created.' ); // @phpstan-ignore class.notFound
			return;
		}

		$run = $this->runs->create_prepared( count( $lines ) );

		if ( null === $run ) {
			\WP_CLI::error( 'Could not create cutover run.' ); // @phpstan-ignore class.notFound
			return;
		}

		\WP_CLI::success( sprintf( 'Cutover run %s created, state=prepared, cohort_count=%d.', $run->run_uuid(), count( $lines ) ) ); // @phpstan-ignore class.notFound
	}

	/**
	 * `activate --run=<uuid> --assume-cutover-authority [--cohort-file=<path>]`:
	 * the only command permitted to write binding status (docs/adr/0042
	 * §2). Re-runs preflight against the identical cohort file immediately
	 * before committing, then the all-or-nothing commit/compensation saga.
	 *
	 * @param array<string, mixed> $assoc_args Flags.
	 */
	private function activate( array $assoc_args ): void {
		if ( ! isset( $assoc_args['assume-cutover-authority'] ) ) {
			\WP_CLI::error( '--assume-cutover-authority is required.' ); // @phpstan-ignore class.notFound
			return;
		}

		$run_uuid = isset( $assoc_args['run'] ) ? (string) $assoc_args['run'] : '';
		$run      = '' !== $run_uuid ? $this->runs->find_by_uuid( $run_uuid ) : null;

		if ( null === $run ) {
			\WP_CLI::error( '--run=<uuid> must name an existing cutover run.' ); // @phpstan-ignore class.notFound
			return;
		}

		if ( CutoverState::PREPARED !== $run->state() && CutoverState::ACTIVATING !== $run->state() ) {
			\WP_CLI::error( sprintf( 'Run %s is in state %s — activate only applies to prepared/activating runs.', $run_uuid, $run->state()->value ) ); // @phpstan-ignore class.notFound
			return;
		}

		$path = isset( $assoc_args['cohort-file'] ) ? (string) $assoc_args['cohort-file'] : '';

		if ( '' === $path || ! is_readable( $path ) ) {
			\WP_CLI::error( '--cohort-file=<path> is required and must be readable (the identical cohort passed to `begin`).' ); // @phpstan-ignore class.notFound
			return;
		}

		$lines     = array_values( array_filter( array_map( 'trim', (array) file( $path ) ) ) );
		$preflight = $this->activation->preflight( $lines );

		if ( ! $preflight['eligible'] ) {
			\WP_CLI::error( 'Cohort is no longer fully eligible — activation refused, no binding touched.' ); // @phpstan-ignore class.notFound
			return;
		}

		$requested_by = $this->current_os_user_id();

		if ( CutoverState::PREPARED === $run->state() ) {
			$this->runs->transition_to_activating( $run->id(), $requested_by );
		}

		$binding_uuids = array_map( static fn( array $r ): string => (string) $r['binding_uuid'], $preflight['results'] );
		$result        = $this->activation->commit( $run->id(), $binding_uuids );

		if ( $result['success'] ) {
			$this->runs->transition_to_activated( $run->id(), $requested_by, 'wp-cli', sprintf( 'activated=%d', count( $result['activated'] ) ) );
			\WP_CLI::success( sprintf( 'Run %s: activated %d binding(s).', $run_uuid, count( $result['activated'] ) ) ); // @phpstan-ignore class.notFound
			return;
		}

		$this->runs->transition_to_activation_failed(
			$run->id(),
			$requested_by,
			'wp-cli',
			sprintf( 'failed_candidate=%s compensated=%d', (string) $result['failed_candidate'], count( $result['compensated'] ) )
		);

		\WP_CLI::error( // @phpstan-ignore class.notFound
			sprintf(
				'Run %s: activation refused at candidate %s. %d already-activated candidate(s) compensated back to prepared. No net binding change.',
				$run_uuid,
				(string) $result['failed_candidate'],
				count( $result['compensated'] )
			)
		);
	}

	/**
	 * `confirm-complete --run=<uuid> --assume-cutover-authority`: the
	 * explicit operator sign-off, gated on the widened backlog predicate
	 * being empty (docs/adr/0042 §1/§3).
	 *
	 * @param array<string, mixed> $assoc_args Flags.
	 */
	private function confirm_complete( array $assoc_args ): void {
		if ( ! isset( $assoc_args['assume-cutover-authority'] ) ) {
			\WP_CLI::error( '--assume-cutover-authority is required.' ); // @phpstan-ignore class.notFound
			return;
		}

		$run_uuid = isset( $assoc_args['run'] ) ? (string) $assoc_args['run'] : '';
		$run      = '' !== $run_uuid ? $this->runs->find_by_uuid( $run_uuid ) : null;

		if ( null === $run || CutoverState::ACTIVATED !== $run->state() ) {
			\WP_CLI::error( 'A run in state "activated" must be named via --run=<uuid>.' ); // @phpstan-ignore class.notFound
			return;
		}

		if ( QuiescenceState::IDLE !== $this->quiescence->state() ) {
			\WP_CLI::error( 'Quiescence must have returned to idle (full replay/handoff drain complete) before confirm-complete.' ); // @phpstan-ignore class.notFound
			return;
		}

		if ( $this->deferred->unresolved_backlog_count() > 0 ) {
			\WP_CLI::error( 'Unresolved deferred-update rows remain (including unresolved incidents) — resolve them before confirm-complete.' ); // @phpstan-ignore class.notFound
			return;
		}

		$this->runs->transition_to_complete( $run->id(), $this->current_os_user_id(), 'wp-cli' );

		\WP_CLI::success( sprintf( 'Run %s is now: complete.', $run_uuid ) ); // @phpstan-ignore class.notFound
	}

	/**
	 * `incident-acknowledge --id=<row> --po-decision-ref=<ref> --assume-cutover-authority`:
	 * the narrowly-scoped, Product-Owner-approved terminal-acknowledgement
	 * exception (docs/adr/0042 §4, Support Chat ADR-0010 §5/PO decision
	 * record). Accepts only an opaque, non-empty reference — rejects
	 * anything that looks like free-form content (whitespace, beyond a
	 * conservative length/character-set bound) rather than trusting the
	 * caller.
	 *
	 * @param array<string, mixed> $assoc_args Flags.
	 */
	private function incident_acknowledge( array $assoc_args ): void {
		if ( ! isset( $assoc_args['assume-cutover-authority'] ) ) {
			\WP_CLI::error( '--assume-cutover-authority is required.' ); // @phpstan-ignore class.notFound
			return;
		}

		$id  = isset( $assoc_args['id'] ) ? (int) $assoc_args['id'] : 0;
		$ref = isset( $assoc_args['po-decision-ref'] ) ? (string) $assoc_args['po-decision-ref'] : '';

		if ( $id <= 0 ) {
			\WP_CLI::error( '--id=<deferred_row_id> is required.' ); // @phpstan-ignore class.notFound
			return;
		}

		if ( '' === $ref || 1 !== preg_match( '/^[A-Za-z0-9._\/-]{1,191}$/', $ref ) ) {
			\WP_CLI::error( '--po-decision-ref must be a non-empty opaque reference (letters, digits, `.`, `_`, `-`, `/` only, max 191 chars) — never free-form content.' ); // @phpstan-ignore class.notFound
			return;
		}

		$row = $this->deferred->find_by_id( $id );

		if ( null === $row ) {
			\WP_CLI::error( sprintf( 'No deferred-update row with id=%d.', $id ) ); // @phpstan-ignore class.notFound
			return;
		}

		if ( null === $row['incident_reason'] ) {
			\WP_CLI::error( sprintf( 'Row id=%d is not a recorded incident — nothing to acknowledge.', $id ) ); // @phpstan-ignore class.notFound
			return;
		}

		if ( null !== $row['incident_resolved_at'] ) {
			\WP_CLI::error( sprintf( 'Row id=%d incident is already resolved.', $id ) ); // @phpstan-ignore class.notFound
			return;
		}

		$this->deferred->resolve_incident_acknowledged( $id, $ref );

		\WP_CLI::success( sprintf( 'Row id=%d incident acknowledged (reason=%s), referencing %s. Ciphertext and audit trail retained permanently; never marked replayed or handed off.', $id, (string) $row['incident_reason'], $ref ) ); // @phpstan-ignore class.notFound
	}

	/**
	 * `recover`: read-only diagnosis of exactly which step is blocking
	 * forward progress — never a state-forcing command (mirroring ADR-0040
	 * §"Alternatives" #9's rejection of a "force-idle" command, applied
	 * here to cutover state).
	 */
	private function recover(): void {
		$run = $this->runs->find_open();

		if ( null === $run ) {
			\WP_CLI::log( 'No open cutover run — nothing to recover.' ); // @phpstan-ignore class.notFound
			return;
		}

		\WP_CLI::log( sprintf( 'Run %s is in state %s.', $run->run_uuid(), $run->state()->value ) ); // @phpstan-ignore class.notFound

		if ( CutoverState::ACTIVATING === $run->state() ) {
			$audit = $this->runs->activation_audit_for_run( $run->id() );
			\WP_CLI::log( sprintf( '%d activation-audit row(s) recorded for this run so far — re-run `activate` with the identical --cohort-file to resume.', count( $audit ) ) ); // @phpstan-ignore class.notFound
		}

		\WP_CLI::log( 'Quiescence state: ' . $this->quiescence->state()->value ); // @phpstan-ignore class.notFound
		\WP_CLI::log( sprintf( 'Unresolved deferred-update backlog: %d', $this->deferred->unresolved_backlog_count() ) ); // @phpstan-ignore class.notFound
	}

	/**
	 * The current OS-shell user's WordPress id, if WP-CLI can resolve one.
	 *
	 * @return int|null
	 */
	private function current_os_user_id(): ?int {
		$id = get_current_user_id();

		return $id > 0 ? $id : null;
	}
}
