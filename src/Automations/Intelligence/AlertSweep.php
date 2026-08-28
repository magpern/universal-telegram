<?php
/**
 * Recurring sweep that evaluates the fixed operational Telegram alerts.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Automations\Intelligence;

use UniversalTelegram\Queue\WorkerRunner;

/**
 * A fixed, idempotently-scheduled Action Scheduler recurring action that
 * runs {@see AlertEvaluator::evaluate()} once per tick. Replaces the alert
 * half of the removed operational-summary sweep (ADR-0044 §1); the
 * per-alert-type 1-hour re-fire cooldown lives in {@see AlertRepository},
 * and the sweep's own concurrency is guarded by the
 * {@see IntelligenceStateRepository} claim-lease mutex.
 */
final class AlertSweep {

	public const JOB_TYPE         = 'universal_telegram_alert_sweep';
	public const INTERVAL_SECONDS = 60;

	private const CLAIM_LEASE_SECONDS = 120;

	/**
	 * Constructor.
	 *
	 * @param AlertEvaluator              $evaluator Evaluates the three fixed alerts.
	 * @param IntelligenceStateRepository $state     The sweep's own claim-lease mutex.
	 */
	public function __construct(
		private readonly AlertEvaluator $evaluator,
		private readonly IntelligenceStateRepository $state
	) {}

	/**
	 * Idempotently registers the recurring sweep action. Safe to call on
	 * every request/activation.
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
	 * The Action Scheduler hook callback: claim the lease, evaluate, release.
	 */
	public function run(): void {
		$token   = wp_generate_uuid4();
		$expires = gmdate( 'Y-m-d H:i:s', time() + self::CLAIM_LEASE_SECONDS );

		if ( ! $this->state->try_claim( $token, $expires ) ) {
			return;
		}

		try {
			$this->evaluator->evaluate();
		} finally {
			$this->state->release( $token );
		}
	}
}
