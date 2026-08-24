<?php
/**
 * Synchronous visitor digest counter increment.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Automations\Digest;

use UniversalTelegram\Events\EventEnvelope;

/**
 * Called once per event by Events\EventDispatcher::handle(), in the same
 * synchronous request path that already writes the history row and
 * evaluates rules (docs/plans/m11a-visitor-activity-digests-plan-v1.md §5).
 * Gated by the exact same DigestEligibility::is_active() check
 * RuleEvaluator's suppression guard uses (one shared gate) — a counter is
 * only ever incremented for an event whose individual dispatch was also
 * suppressed, so the digest always reconciles with "what was not sent
 * individually", never with events also delivered individually.
 */
final class VisitorDigestAggregator {

	/**
	 * Event type to fixed digest category. Deliberately the same seven keys
	 * as DigestEligibility::SUPPRESSED_EVENT_TYPES — every suppressed event
	 * type is aggregated, and only those.
	 *
	 * @var array<string, string>
	 */
	private const CATEGORY_MAP = array(
		'visitor.page_viewed'             => 'page_views',
		'visitor.navigation'              => 'page_views',
		'visitor.product_viewed'          => 'product_views',
		'visitor.search_performed'        => 'search',
		'visitor.add_to_cart_intent'      => 'cart_intent',
		'visitor.checkout_started_intent' => 'cart_intent',
		'visitor.session_started'         => 'other',
	);

	/**
	 * Constructor.
	 *
	 * @param DigestEligibility              $eligibility The shared active/eligibility gate.
	 * @param VisitorDigestCounterRepository $counters    Bucket increment persistence.
	 * @param VisitorDigestStateRepository   $state       Window open/checkpoint persistence.
	 */
	public function __construct(
		private readonly DigestEligibility $eligibility,
		private readonly VisitorDigestCounterRepository $counters,
		private readonly VisitorDigestStateRepository $state
	) {}

	/**
	 * Increments the appropriate (window, category, page_type) bucket for
	 * one event occurrence, or does nothing at all if the event type is not
	 * digest-eligible or the digest is not currently active.
	 *
	 * @param EventEnvelope $event The event occurrence.
	 */
	public function record( EventEnvelope $event ): void {
		$event_type = $event->event_type();

		if ( ! isset( self::CATEGORY_MAP[ $event_type ] ) ) {
			return;
		}

		if ( ! $this->eligibility->is_active() ) {
			return;
		}

		$category  = self::CATEGORY_MAP[ $event_type ];
		$page_type = 'visitor.page_viewed' === $event_type ? (string) ( $event->value_at( 'subject.page_type' ) ?? '' ) : '';

		$window_started_at = $this->state->open_window_if_needed( current_time( 'mysql', true ) );

		$this->counters->increment( $window_started_at, $category, $page_type );
	}
}
