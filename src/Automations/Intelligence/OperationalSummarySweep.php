<?php
/**
 * Operational-summary daily aggregation, scheduling, and send-handoff sweep.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Automations\Intelligence;

use UniversalTelegram\Automations\Digest\DigestEligibility;
use UniversalTelegram\Integrations\WooCommerce\WooCommerceSupport;
use UniversalTelegram\Queue\DispatchState;
use UniversalTelegram\Queue\WorkerRunner;
use UniversalTelegram\Telegram\Outbound\MessageDispatcher;

/**
 * Registered as a fixed, idempotently-scheduled Action Scheduler recurring
 * action, exactly like Automations\Digest\VisitorDigestSweep and
 * AI\Draft\AiDraftLeaseSweep before it
 * (docs/plans/m11b-digests-and-operational-intelligence-plan-v1.md §2.1/§6).
 * Ticks every 60 seconds but only *acts* once per UTC calendar day, at a
 * configurable hour: before that hour, or once today's row is already
 * sent, each tick is a cheap no-op. Row creation for a given UTC day is
 * structurally exactly-once via OperationalSummaryRepository's own
 * summary_date UNIQUE constraint; message send remains at-least-once,
 * honestly, guarded by IntelligenceStateRepository's claim-lease mutex —
 * the same two-tier duplicate/crash posture M11A's own digest window
 * already established. Also evaluates the three fixed threshold alerts
 * (§2.2) on the same tick, added in a later work package.
 */
final class OperationalSummarySweep {

	public const JOB_TYPE            = 'operational_summary_sweep';
	public const INTERVAL_SECONDS    = 60;
	public const CLAIM_LEASE_SECONDS = 120;

	/**
	 * Constructor.
	 *
	 * @param IntelligenceSettings           $settings            Supplies the operational_summary_* fields.
	 * @param DigestEligibility              $eligibility         Reused destination-eligibility rule (M11A §4).
	 * @param OperationalSummaryRepository   $repository          Row persistence and event_history aggregation.
	 * @param IntelligenceStateRepository    $state               The sweep's own claim-lease mutex.
	 * @param OperationalSummaryRenderer     $renderer             Fixed message rendering.
	 * @param MessageDispatcher              $message_dispatcher  M01's own, unchanged outbound transport.
	 * @param WooCommerceSupport             $woocommerce_support Governs whether commerce fields render.
	 */
	public function __construct(
		private readonly IntelligenceSettings $settings,
		private readonly DigestEligibility $eligibility,
		private readonly OperationalSummaryRepository $repository,
		private readonly IntelligenceStateRepository $state,
		private readonly OperationalSummaryRenderer $renderer,
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
		$this->run_operational_summary();
	}

	/**
	 * The Operational Summary half of the tick: create-or-return today's
	 * row once the configured hour is reached, populate its counts, and
	 * hand a rendered message off through the unmodified M01 outbound path.
	 */
	private function run_operational_summary(): void {
		if ( ! $this->settings->operational_summary_enabled() ) {
			return;
		}

		$bot_id         = $this->settings->operational_summary_bot_id();
		$destination_id = $this->settings->operational_summary_destination_id();

		if ( null === $bot_id || null === $destination_id || ! $this->eligibility->destination_is_eligible( $bot_id, $destination_id ) ) {
			// Enabled but misconfigured: no row is created, no send is
			// attempted, and the condition is re-checked every tick —
			// resumes automatically once the target is repaired.
			return;
		}

		$current_hour_utc = (int) gmdate( 'G' );

		if ( $current_hour_utc < $this->settings->operational_summary_hour_utc() ) {
			return;
		}

		$summary_date      = gmdate( 'Y-m-d' );
		$window_started_at = gmdate( 'Y-m-d 00:00:00' );
		$window_ended_at   = current_time( 'mysql', true );

		$row = $this->repository->create_or_get_for_date( $summary_date, $window_started_at, $window_ended_at );

		if ( null === $row ) {
			return;
		}

		if ( 'sent' === $row['send_status'] ) {
			return;
		}

		$woocommerce_active = $this->woocommerce_support->is_active();

		$counts = array(
			'orders_created'     => $this->repository->count_event_type_since( 'woocommerce.order_created', $window_started_at ),
			'payments_completed' => $this->repository->count_event_type_since( 'woocommerce.payment_completed', $window_started_at ),
			'orders_failed'      => $this->repository->count_event_type_since( 'woocommerce.order_failed', $window_started_at ),
			'orders_cancelled'   => $this->repository->count_event_type_since( 'woocommerce.order_cancelled', $window_started_at ),
			'checkout_failures'  => $this->repository->count_event_type_since( 'woocommerce.checkout_validation_failed', $window_started_at ),
		);

		$this->repository->save_counts( (int) $row['id'], $counts, $woocommerce_active );

		$row = $this->repository->find( (int) $row['id'] );

		if ( null === $row ) {
			return;
		}

		$claim_token      = wp_generate_uuid4();
		$claim_expires_at = gmdate( 'Y-m-d H:i:s', time() + self::CLAIM_LEASE_SECONDS );

		if ( ! $this->state->try_claim( $claim_token, $claim_expires_at ) ) {
			// Another concurrent tick already claimed the send — safe no-op.
			return;
		}

		$text   = $this->renderer->render( $row, $woocommerce_active );
		$result = $this->message_dispatcher->send( $bot_id, $destination_id, $text, 'MarkdownV2' );

		if ( null !== $result && DispatchState::SCHEDULED === $result->state() ) {
			$this->repository->mark_sent( (int) $row['id'], current_time( 'mysql', true ) );
			$this->state->release( $claim_token );
			return;
		}

		// Leave the row as-is (send_status untouched, no data loss) — the
		// next tick retries, matching M11A's own crash/duplicate posture.
		$this->repository->mark_send_status( (int) $row['id'], 'send_failed' );
		$this->state->release( $claim_token );
	}
}
