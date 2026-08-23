<?php
/**
 * Stale generation-lease recovery sweep.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\AI\Draft;

use UniversalTelegram\Queue\WorkerRunner;

/**
 * The sole durable recovery trigger for a crashed worker's expired lease
 * (docs/adr/0028 decision 5, §3.5 of the frozen plan). A lease field alone
 * does not schedule anything — nothing else in this codebase would ever
 * notice an expired lease and re-dispatch. Registered once, at plugin
 * init, as a fixed, idempotently-scheduled Action Scheduler recurring
 * action; reuses the same queue mechanism every other job type already
 * uses, no bespoke cron or polling loop.
 *
 * Each candidate row is handled via one of two atomic, self-verifying
 * compare-and-set updates (AiDraftRepository::try_reclaim_stale() /
 * try_exhaust_stale()) — never an explicit transaction — so two
 * overlapping sweep runs can never both win the same row.
 */
final class AiDraftLeaseSweep {

	public const JOB_TYPE                      = 'ai_draft_lease_sweep';
	public const INTERVAL_SECONDS              = 60;
	public const MAX_ATTEMPTS_BEFORE_EXHAUSTED = 5;

	/**
	 * Constructor.
	 *
	 * @param AiDraftRepository $drafts Draft persistence, claim, and lease.
	 */
	public function __construct( private readonly AiDraftRepository $drafts ) {}

	/**
	 * Idempotently registers the recurring sweep action. Safe to call on
	 * every request/activation — as_has_scheduled_action() guards against
	 * duplicate recurring schedules.
	 */
	public function register(): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}

		if ( as_has_scheduled_action( self::JOB_TYPE, array(), WorkerRunner::GROUP ) ) {
			return;
		}

		as_schedule_recurring_action( time(), self::INTERVAL_SECONDS, self::JOB_TYPE, array(), WorkerRunner::GROUP );
	}

	/**
	 * The Action Scheduler hook callback. Cheap and a no-op whenever no
	 * `generating` row has an expired lease, so it costs nothing to leave
	 * scheduled while AI is disabled.
	 */
	public function run(): void {
		foreach ( $this->drafts->find_stale_generating() as $draft ) {
			if ( $draft->attempt_count() < self::MAX_ATTEMPTS_BEFORE_EXHAUSTED ) {
				if ( $this->drafts->try_reclaim_stale( $draft->id() ) ) {
					$this->reenqueue( $draft );
				}
				continue;
			}

			// The shared attempt budget is already exhausted — dead-letter
			// rather than re-arm, regardless of whether prior attempts
			// failed via a caught exception or a process crash.
			$this->drafts->try_exhaust_stale( $draft->id() );
		}
	}

	/**
	 * Schedules a fresh attempt for a reclaimed row and records the new
	 * action id as its current job_reference.
	 *
	 * @param AiDraft $draft The reclaimed draft.
	 */
	private function reenqueue( AiDraft $draft ): void {
		$args = array(
			array(
				'job_id'   => wp_generate_uuid4(),
				'job_type' => AIDraftGenerationHandler::JOB_TYPE,
				'attempt'  => 1,
				'payload'  => array(
					'draft_uuid' => $draft->draft_uuid(),
				),
			),
		);

		$action_id = as_schedule_single_action( time(), WorkerRunner::HOOK, $args, WorkerRunner::GROUP );

		// Action Scheduler's own stub types this as always int, but its
		// documented behaviour returns 0 on several internal failure paths;
		// is_int() is kept for defensive robustness against a future
		// return-type change, matching Dispatcher's identical guard.
		// phpcs:ignore Squiz.Commenting.InlineComment.InvalidEndChar
		// @phpstan-ignore function.alreadyNarrowedType
		if ( is_int( $action_id ) && $action_id > 0 ) {
			$this->drafts->set_job_reference( $draft->id(), (string) $action_id );
		}
	}
}
