<?php
/**
 * Fixed operational-summary message rendering.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Automations\Intelligence;

/**
 * Renders the fixed-structure daily operational summary message
 * (docs/plans/m11b-digests-and-operational-intelligence-plan-v1.md §2.1/§2.4/§2.5)
 * from one operational_summary_runs row. Every value placed into the
 * message is either a fixed label or a bounded non-negative integer —
 * never a raw field, order ID, URL, or any other variable content — so, as
 * with VisitorDigestRenderer, no MarkdownV2 escaping is required.
 */
final class OperationalSummaryRenderer {

	/**
	 * Renders the fixed summary message for one row.
	 *
	 * @param array<string, mixed> $row                The operational_summary_runs row.
	 * @param bool                  $woocommerce_active Whether commerce lines render at all.
	 *
	 * @return string
	 */
	public function render( array $row, bool $woocommerce_active ): string {
		$lines   = array();
		$lines[] = '📈 *Daily Operations Summary*';
		$lines[] = sprintf( 'Window: %s – %s', $row['window_started_at'], $row['window_ended_at'] );
		$lines[] = '';

		if ( $woocommerce_active ) {
			$lines[] = sprintf( 'Orders created: %d', (int) $row['orders_created'] );
			$lines[] = sprintf( 'Payments completed: %d', (int) $row['payments_completed'] );
			$lines[] = sprintf( 'Orders failed: %d', (int) $row['orders_failed'] );
			$lines[] = sprintf( 'Orders cancelled: %d', (int) $row['orders_cancelled'] );
			$lines[] = sprintf( 'Checkout failures: %d', (int) $row['checkout_failures'] );
			$lines[] = '';
		}

		$js_error_total = (int) $row['js_error_runtime'] + (int) $row['js_error_promise'] + (int) $row['js_error_resource'];
		$lines[]        = sprintf( 'JavaScript errors: %d', $js_error_total );
		if ( $js_error_total > 0 ) {
			$lines[] = sprintf(
				'  • Runtime: %d   • Promise rejection: %d   • Resource load: %d',
				(int) $row['js_error_runtime'],
				(int) $row['js_error_promise'],
				(int) $row['js_error_resource']
			);
		}

		$lines[] = '';
		$lines[] = 'Funnel:';
		$lines[] = sprintf( '  Product views: %d', (int) $row['funnel_product_views'] );
		$lines[] = sprintf( '  Cart intents: %d', (int) $row['funnel_cart_intents'] );
		$lines[] = sprintf( '  Checkout starts: %d', (int) $row['funnel_checkout_starts'] );
		if ( $woocommerce_active ) {
			$lines[] = sprintf( '  Orders created: %d', (int) $row['funnel_orders_created'] );
		}

		return implode( "\n", $lines );
	}
}
