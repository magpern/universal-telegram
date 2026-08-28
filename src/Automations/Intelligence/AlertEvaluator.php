<?php
/**
 * Fixed threshold-alert catalogue evaluation.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Automations\Intelligence;

use UniversalTelegram\Automations\EventCountAggregator;
use UniversalTelegram\Telegram\Configuration\DestinationEligibility;
use UniversalTelegram\Integrations\WooCommerce\WooCommerceSupport;
use UniversalTelegram\Telegram\Outbound\MessageDispatcher;

/**
 * Evaluates the three fixed threshold alert types
 * (docs/plans/m11b-digests-and-operational-intelligence-plan-v1.md §2.2) on
 * every alert-sweep tick. Each
 * alert type is independently toggleable, default disabled, and bounded by
 * AlertRepository's own fixed 1-hour re-fire cooldown, the structural
 * anti-flood guarantee: this class never fires the same alert type twice
 * within an hour, regardless of how long the condition persists.
 */
final class AlertEvaluator {

	private const WINDOW_SECONDS = HOUR_IN_SECONDS;

	/**
	 * Constructor.
	 *
	 * @param IntelligenceSettings   $settings            Supplies the alert_* fields.
	 * @param DestinationEligibility $eligibility         Destination-eligibility rule.
	 * @param EventCountAggregator   $counts              Bounded event_history aggregation.
	 * @param AlertRepository        $alert_state         Cooldown/checkpoint persistence.
	 * @param MessageDispatcher      $message_dispatcher  M01's own, unchanged outbound transport.
	 * @param WooCommerceSupport     $woocommerce_support Governs WC-gated alert inertness.
	 */
	public function __construct(
		private readonly IntelligenceSettings $settings,
		private readonly DestinationEligibility $eligibility,
		private readonly EventCountAggregator $counts,
		private readonly AlertRepository $alert_state,
		private readonly MessageDispatcher $message_dispatcher,
		private readonly WooCommerceSupport $woocommerce_support
	) {}

	/**
	 * Evaluates every fixed alert type once.
	 */
	public function evaluate(): void {
		$woocommerce_active = $this->woocommerce_support->is_active();

		$this->evaluate_count_alert( 'checkout_failure_count', 'woocommerce.checkout_validation_failed', $woocommerce_active );
		$this->evaluate_count_alert( 'order_failure_spike', 'woocommerce.order_failed', $woocommerce_active );
		$this->evaluate_js_error_spike();
	}

	/**
	 * Evaluates a simple total-count-over-window alert type, WC-gated.
	 *
	 * @param string $alert_type          One of IntelligenceSettings::ALERT_TYPES.
	 * @param string $event_type          The source event type.
	 * @param bool   $woocommerce_active  Whether WooCommerce is currently active.
	 */
	private function evaluate_count_alert( string $alert_type, string $event_type, bool $woocommerce_active ): void {
		if ( ! $woocommerce_active ) {
			// Structurally inert: the source event cannot occur at all.
			return;
		}

		if ( ! $this->settings->alert_enabled( $alert_type ) ) {
			return;
		}

		$since = gmdate( 'Y-m-d H:i:s', time() - self::WINDOW_SECONDS );
		$count = $this->counts->count_event_type_since( $event_type, $since );

		if ( $count < $this->settings->alert_threshold( $alert_type ) ) {
			return;
		}

		$this->fire( $alert_type, sprintf( '%d in the last hour', $count ) );
	}

	/**
	 * Evaluates the js_error_spike alert type: fires if any single bounded
	 * error_category reaches the configured threshold within the window —
	 * not WC-gated.
	 */
	private function evaluate_js_error_spike(): void {
		if ( ! $this->settings->alert_enabled( 'js_error_spike' ) ) {
			return;
		}

		$since     = gmdate( 'Y-m-d H:i:s', time() - self::WINDOW_SECONDS );
		$threshold = $this->settings->alert_threshold( 'js_error_spike' );

		foreach ( array( 'runtime', 'promise_rejection', 'resource_load' ) as $category ) {
			$count = $this->counts->count_error_category_since( $category, $since );

			if ( $count >= $threshold ) {
				$this->fire( 'js_error_spike', sprintf( '%d "%s" errors in the last hour', $count, $category ) );
				return;
			}
		}
	}

	/**
	 * Claims and sends one alert firing message, if the destination is
	 * currently eligible and the 1-hour cooldown permits it.
	 *
	 * @param string $alert_type   One of IntelligenceSettings::ALERT_TYPES.
	 * @param string $count_phrase A human-readable count+window phrase.
	 */
	private function fire( string $alert_type, string $count_phrase ): void {
		$bot_id         = $this->settings->alert_bot_id();
		$destination_id = $this->settings->alert_destination_id();

		if ( null === $bot_id || null === $destination_id || ! $this->eligibility->destination_is_eligible( $bot_id, $destination_id ) ) {
			return;
		}

		$now = current_time( 'mysql', true );

		if ( ! $this->alert_state->try_fire( $alert_type, $now ) ) {
			// Already fired within the last hour — the structural
			// anti-flood guarantee; never fires per-event.
			return;
		}

		$labels = array(
			'checkout_failure_count' => __( 'Checkout failure alert', 'universal-telegram' ),
			'order_failure_spike'    => __( 'Order failure alert', 'universal-telegram' ),
			'js_error_spike'         => __( 'Error spike alert', 'universal-telegram' ),
		);

		$text = sprintf(
			"⚠️ *%s*\n%s",
			$labels[ $alert_type ] ?? $alert_type,
			$count_phrase
		);

		// A send failure is absorbed by M01's own existing retry/circuit-
		// breaker machinery once send() has been called; this alert's own
		// 1-hour cooldown already prevents any re-fire storm regardless of
		// the outcome, so no additional bookkeeping is needed here.
		$this->message_dispatcher->send( $bot_id, $destination_id, $text, 'MarkdownV2' );
	}
}
