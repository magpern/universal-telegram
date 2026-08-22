<?php
/**
 * Expedited queue dispatch trigger.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Queue;

use ActionScheduler;
use ActionScheduler_AsyncRequest_QueueRunner;
use Throwable;
use UniversalTelegram\Audit\AuditLogger;
use UniversalTelegram\Privacy\Classification;

/**
 * After an interactive job is durably enqueued via the unmodified
 * Queue\Dispatcher::enqueue(), requests an out-of-band, non-blocking
 * loopback dispatch of Action Scheduler's own existing async queue runner
 * — the same mechanism Action Scheduler's own admin-context "shutdown"
 * hook already uses, reached here without that hook's is_admin() gate
 * (docs/adr/0023). Strictly an optimization: every branch fails safe to
 * the durable, already-enqueued job and normal cron cadence, never
 * throwing and never blocking the caller (docs/adr/0023 §1).
 */
class ExpeditedDispatchTrigger {

	/**
	 * Constructor.
	 *
	 * @param AuditLogger $audit Records only the two fixed, abnormal outcome codes (docs/adr/0023 §3).
	 */
	public function __construct( private readonly AuditLogger $audit ) {}

	/**
	 * Requests expedited processing of whatever is currently due. Never
	 * throws; the durable job remains untouched regardless of outcome.
	 */
	public function trigger(): void {
		try {
			if ( ! $this->dependency_available() ) {
				$this->record( 'expedited_dispatch_unavailable' );
				return;
			}

			if ( $this->declined_for_concurrency() ) {
				$this->record( 'expedited_dispatch_declined_concurrency' );
				return;
			}

			$runner = $this->create_runner();

			if ( ! is_object( $runner ) || ! method_exists( $runner, 'maybe_dispatch' ) ) {
				$this->record( 'expedited_dispatch_unavailable' );
				return;
			}

			$runner->maybe_dispatch();
			// No audit entry on the routine-success path: a fire-and-forget
			// request proves nothing about outcome, and the audit log table
			// has no retention policy to absorb one row per visitor message
			// (docs/adr/0023 §3).
		} catch ( Throwable $exception ) {
			$this->record( 'expedited_dispatch_unavailable' );
		}
	}

	/**
	 * Overridable by tests to simulate an unavailable Action Scheduler
	 * install without needing to actually remove the dependency.
	 *
	 * @return bool
	 */
	protected function dependency_available(): bool {
		return class_exists( ActionScheduler::class )
			&& class_exists( ActionScheduler_AsyncRequest_QueueRunner::class );
	}

	/**
	 * Overridable by tests to force the declined-concurrency branch
	 * without needing a real second in-flight batch. Mirrors exactly the
	 * public conditions Action Scheduler's own async runner checks before
	 * dispatching.
	 *
	 * @return bool
	 */
	protected function declined_for_concurrency(): bool {
		return ActionScheduler::runner()->has_maximum_concurrent_batches()
			|| ! ActionScheduler::store()->has_pending_actions_due();
	}

	/**
	 * Deliberately untyped return: production returns a real
	 * ActionScheduler_AsyncRequest_QueueRunner, but tests override this to
	 * return a stub missing maybe_dispatch(), one that throws from it, or
	 * to throw during construction itself — none of which a declared
	 * return type would let PHP accept, defeating the point of the seam.
	 * Mirrors Queue\Dispatcher::schedule_action()'s own documented
	 * test-override precedent; production code never overrides either.
	 *
	 * @return object
	 */
	protected function create_runner() {
		return new ActionScheduler_AsyncRequest_QueueRunner( ActionScheduler::store() );
	}

	/**
	 * Records one of the two fixed, abnormal-outcome codes. Never the
	 * exception's own message, which could vary.
	 *
	 * @param string $action The fixed audit action code.
	 */
	private function record( string $action ): void {
		$this->audit->record(
			$action,
			'system',
			null,
			array(),
			array(),
			Classification::INTERNAL
		);
	}
}
