<?php
/**
 * Typed operational-summary aggregate row.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Automations\Intelligence;

/**
 * A typed, fixed-shape view of one universal_telegram_operational_summary_runs
 * row — every property is an integer, string date, or boolean, structurally
 * incapable of holding raw event data, free text, or an arbitrary-shape
 * array. This is the only input OperationalSummaryPromptBuilder::build()
 * accepts (docs/plans/m11b-digests-and-operational-intelligence-plan-v1.md
 * §3), mirroring ADR-0028 decision 2's structural pattern: a caller cannot
 * pass raw event/order data into a prompt even by mistake, because this
 * class's own constructor has no parameter shaped to accept it.
 */
final class OperationalSummaryRow {

	/**
	 * Constructor.
	 *
	 * @param int    $id                     The row's own primary key.
	 * @param string $summary_date           The UTC calendar day, 'Y-m-d'.
	 * @param int    $orders_created         Order-created event count.
	 * @param int    $payments_completed     Payment-completed event count.
	 * @param int    $orders_failed          Order-failed event count.
	 * @param int    $orders_cancelled       Order-cancelled event count.
	 * @param int    $checkout_failures      Checkout-validation-failure count.
	 * @param int    $js_error_runtime       Runtime-category JS error count.
	 * @param int    $js_error_promise       Promise-rejection-category JS error count.
	 * @param int    $js_error_resource      Resource-load-category JS error count.
	 * @param int    $funnel_product_views   Product-view funnel-stage count.
	 * @param int    $funnel_cart_intents    Cart-intent funnel-stage count.
	 * @param int    $funnel_checkout_starts Checkout-start funnel-stage count.
	 * @param int    $funnel_orders_created  Order-created funnel-stage count.
	 * @param bool   $woocommerce_active     Whether WooCommerce was active when this row was computed.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $summary_date,
		public readonly int $orders_created,
		public readonly int $payments_completed,
		public readonly int $orders_failed,
		public readonly int $orders_cancelled,
		public readonly int $checkout_failures,
		public readonly int $js_error_runtime,
		public readonly int $js_error_promise,
		public readonly int $js_error_resource,
		public readonly int $funnel_product_views,
		public readonly int $funnel_cart_intents,
		public readonly int $funnel_checkout_starts,
		public readonly int $funnel_orders_created,
		public readonly bool $woocommerce_active
	) {}
}
