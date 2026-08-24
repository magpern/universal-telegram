<?php
/**
 * Operational-summary AI stale generation-lease recovery sweep.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Automations\Intelligence;

use UniversalTelegram\Queue\WorkerRunner;

/**
 * The sole durable recovery trigger for a crashed worker's expired
 * operational-summary AI generation lease
 * (docs/plans/m11b-digests-and-operational-intelligence-plan-v1.md §3),
 * mirroring AI\Draft\AiDraftLeaseSweep exactly. Registered once, at plugin
 * init, as a fixed, idempotently-scheduled Action Scheduler recurring
 * action.
 */
final class SummaryAiLeaseSweep {

	public const JOB_TYPE                      = 'operational_summary_ai_lease_sweep';
	public const INTERVAL_SECONDS              = 60;
	public const MAX_ATTEMPTS_BEFORE_EXHAUSTED = 5;

	/**
	 * Constructor.
	 *
	 * @param SummaryAiRepository $drafts Draft persistence, claim, and lease.
	 */
	public function __construct( private readonly SummaryAiRepository $drafts ) {}

	/**
	 * Idempotently registers the recurring sweep action.
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
	 * The Action Scheduler hook callback.
	 */
	public function run(): void {
		foreach ( $this->drafts->find_stale_generating() as $draft ) {
			if ( $draft->attempt_count() < self::MAX_ATTEMPTS_BEFORE_EXHAUSTED ) {
				if ( $this->drafts->try_reclaim_stale( $draft->id() ) ) {
					$this->reenqueue( $draft );
				}
				continue;
			}

			$this->drafts->try_exhaust_stale( $draft->id() );
		}
	}

	/**
	 * Schedules a fresh attempt for a reclaimed row.
	 *
	 * @param SummaryAiDraft $draft The reclaimed draft.
	 */
	private function reenqueue( SummaryAiDraft $draft ): void {
		$args = array(
			array(
				'job_id'   => wp_generate_uuid4(),
				'job_type' => SummaryAiGenerationHandler::JOB_TYPE,
				'attempt'  => 1,
				'payload'  => array(
					'draft_uuid' => $draft->draft_uuid(),
				),
			),
		);

		as_schedule_single_action( time(), WorkerRunner::HOOK, $args, WorkerRunner::GROUP );
	}
}
