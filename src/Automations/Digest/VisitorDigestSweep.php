<?php
/**
 * Visitor digest threshold/max-wait evaluation sweep.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Automations\Digest;

use UniversalTelegram\Core\Configuration\Settings;
use UniversalTelegram\Integrations\WooCommerce\WooCommerceSupport;
use UniversalTelegram\Queue\DispatchState;
use UniversalTelegram\Queue\WorkerRunner;
use UniversalTelegram\Telegram\Outbound\MessageDispatcher;

/**
 * Registered as a fixed, idempotently-scheduled Action Scheduler recurring
 * action, exactly like every other sweep in this codebase
 * (AI\Draft\AiDraftLeaseSweep's own precedent) —
 * docs/plans/m11a-visitor-activity-digests-plan-v1.md §5. Each tick:
 * refreshes the shared DigestEligibility cache unconditionally (so a
 * target-validity regression is caught even with no visitor traffic to
 * trigger it); if a window is open and active, evaluates the frozen
 * threshold-or-max-wait condition and, if eligible, claims and hands the
 * rendered digest off through the unmodified M01 outbound path. Never
 * blocks a frontend request — runs entirely on Action Scheduler's own
 * background worker.
 */
final class VisitorDigestSweep {

	public const JOB_TYPE             = 'visitor_digest_evaluation_sweep';
	public const INTERVAL_SECONDS     = 60;
	public const CLAIM_LEASE_SECONDS  = 120;

	/**
	 * Constructor.
	 *
	 * @param Settings                       $settings            Supplies the five visitor_digest_* fields.
	 * @param DigestEligibility              $eligibility         The shared active/eligibility gate.
	 * @param VisitorDigestStateRepository   $state               Window/claim checkpoint persistence.
	 * @param VisitorDigestCounterRepository $counters            Bucket read/sum/delete persistence.
	 * @param VisitorDigestRenderer          $renderer            Fixed message rendering.
	 * @param MessageDispatcher              $message_dispatcher  M01's own, unchanged outbound transport.
	 * @param WooCommerceSupport             $woocommerce_support Governs whether commerce lines render.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly DigestEligibility $eligibility,
		private readonly VisitorDigestStateRepository $state,
		private readonly VisitorDigestCounterRepository $counters,
		private readonly VisitorDigestRenderer $renderer,
		private readonly MessageDispatcher $message_dispatcher,
		private readonly WooCommerceSupport $woocommerce_support
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
	 * The Action Scheduler hook callback.
	 */
	public function run(): void {
		// Refreshed unconditionally, regardless of whether a window is
		// open, so a target-validity regression is caught within one tick
		// even during a quiet period with no visitor traffic to trigger a
		// natural recompute (§3.1).
		$this->eligibility->refresh();

		$window_started_at = $this->state->current_window_started_at();

		if ( null === $window_started_at ) {
			return;
		}

		if ( ! $this->eligibility->is_active() ) {
			// The window is left exactly as-is — neither sent nor
			// discarded — and re-checked again next tick, resuming
			// automatically once is_active() returns true (§5).
			$this->state->record_skip( 'skipped_invalid_target' );
			return;
		}

		$sum = $this->counters->sum_for_window( $window_started_at );

		if ( ! $this->is_eligible_to_send( $sum, $window_started_at ) ) {
			return;
		}

		$values           = $this->settings->get();
		$claim_token      = wp_generate_uuid4();
		$claim_expires_at = gmdate( 'Y-m-d H:i:s', time() + self::CLAIM_LEASE_SECONDS );

		if ( ! $this->state->try_claim_for_send( $window_started_at, $claim_token, $claim_expires_at ) ) {
			// Another concurrent tick (overlapping cron and a manual
			// trigger) already claimed this window — safe no-op.
			return;
		}

		$rows = $this->counters->for_window( $window_started_at );
		$now  = current_time( 'mysql', true );
		$text = $this->renderer->render( $window_started_at, $now, $rows, $this->woocommerce_support->is_active() );

		$result = $this->message_dispatcher->send( (int) $values['visitor_digest_bot_id'], (int) $values['visitor_digest_destination_id'], $text, 'MarkdownV2' );

		if ( null !== $result && DispatchState::SCHEDULED === $result->state() ) {
			if ( $this->state->close_window_after_send( $window_started_at, $claim_token, $now ) ) {
				$this->counters->delete_for_window( $window_started_at );
			}
			return;
		}

		// Leave the window open — no data loss; the next sweep tick
		// retries. M01's own outbound retry/circuit-breaker (ADR-0014)
		// governs the underlying Telegram-delivery reliability; this is
		// not a new retry mechanism.
		$this->state->release_claim_after_failure( $claim_token, 'send_failed' );
	}

	/**
	 * Either-condition-met eligibility: the accumulated count has reached
	 * the configured threshold, or the configured maximum wait has elapsed
	 * since the window opened (frozen decision 3).
	 *
	 * @param int    $sum                The window's own accumulated total.
	 * @param string $window_started_at  The window's own open timestamp.
	 *
	 * @return bool
	 */
	private function is_eligible_to_send( int $sum, string $window_started_at ): bool {
		$values = $this->settings->get();

		if ( $sum >= (int) $values['visitor_digest_threshold'] ) {
			return true;
		}

		$started = strtotime( $window_started_at . ' UTC' );

		if ( false === $started ) {
			return false;
		}

		$max_wait_seconds = (int) $values['visitor_digest_max_wait_minutes'] * MINUTE_IN_SECONDS;

		return ( time() - $started ) >= $max_wait_seconds;
	}
}
