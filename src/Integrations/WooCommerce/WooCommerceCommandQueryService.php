<?php
/**
 * Bounded, read-only WooCommerce queries for administrative bot commands.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Integrations\WooCommerce;

use DateTimeImmutable;

/**
 * Backs M08's `/orders`, `/order`, `/stock`, and `/sales` administrative
 * bot commands (ADR-0027). Read-only, documented-API-only, no persistence,
 * no REST route, no background task, no mutation. Order access reuses the
 * exact HPOS-safe `wc_get_order()` pattern `OrderEventEmitter` already
 * uses. `/orders` and `/sales` never call `wc_get_orders()` with
 * `'limit' => -1` and never load an unbounded order set: both first issue
 * a cheap `'paginate' => true` count-only probe, then either compute an
 * exact result from at most `ceil(total / 100)` further 100-row pages
 * when the total is within SAFE_PROCESSING_CAP, or return a distinguished
 * cap-exceeded result — never a partial one.
 */
final class WooCommerceCommandQueryService {

	private const PAGE_SIZE           = 100;
	private const SAFE_PROCESSING_CAP = 500;

	/**
	 * Whether a count-only probe's result is within the safe processing
	 * cap. Pure and static — independently unit-testable with no
	 * WordPress/WooCommerce bootstrap, exactly the "501 refusal" boundary
	 * this class's own callers rely on.
	 *
	 * @param int $total The count-only probe's own `->total`.
	 *
	 * @return bool
	 */
	public static function is_within_safe_cap( int $total ): bool {
		return $total <= self::SAFE_PROCESSING_CAP;
	}

	/**
	 * The exact number of PAGE_SIZE-row pages needed to fetch a
	 * within-cap total — at most 5, since is_within_safe_cap() is always
	 * checked first. Pure and static.
	 *
	 * @param int $total The count-only probe's own `->total`.
	 *
	 * @return int
	 */
	public static function pages_needed( int $total ): int {
		return (int) ceil( $total / self::PAGE_SIZE );
	}

	/**
	 * A single order's fixed, narrow field set — status, site-timezone
	 * date, currency, total, item count. Never customer, payment,
	 * shipping, coupon, note, or line-item product-name data.
	 *
	 * @param int $order_id The requested order id.
	 *
	 * @return array{status: string, date_created: string, currency: string, total: string, item_count: int}|null
	 *               Null when not found, not retrievable, or not a normal order object — the caller renders the
	 *               identical "not found or unavailable" text regardless of cause.
	 */
	public function order_summary( int $order_id ): ?array {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return null;
		}

		$order = wc_get_order( $order_id );

		if ( ! is_object( $order ) || ! method_exists( $order, 'get_status' ) ) {
			return null;
		}

		$date_created = $order->get_date_created();

		return array(
			'status'       => $order->get_status(),
			'date_created' => null !== $date_created ? wp_date( 'Y-m-d H:i', $date_created->getTimestamp() ) : '',
			'currency'     => $order->get_currency(),
			'total'        => $order->get_total(),
			'item_count'   => count( $order->get_items() ),
		);
	}

	/**
	 * A single product's fixed, narrow stock field set. The submitted SKU
	 * is used only as a lookup key — never included in the return value.
	 *
	 * @param string $sku The submitted SKU.
	 *
	 * @return array{name: string, manages_stock: bool, stock_quantity: int|null, stock_status: string}|null
	 *               Null when no matching product exists or it is not retrievable.
	 */
	public function stock_summary( string $sku ): ?array {
		if ( ! function_exists( 'wc_get_product_id_by_sku' ) || ! function_exists( 'wc_get_product' ) ) {
			return null;
		}

		$product_id = wc_get_product_id_by_sku( $sku );

		if ( 0 === $product_id ) {
			return null;
		}

		$product = wc_get_product( $product_id );

		if ( ! is_object( $product ) || ! method_exists( $product, 'get_name' ) ) {
			return null;
		}

		$manages_stock = $product->managing_stock();

		return array(
			'name'           => $product->get_name(),
			'manages_stock'  => (bool) $manages_stock,
			'stock_quantity' => $manages_stock ? $product->get_stock_quantity() : null,
			'stock_status'   => $product->get_stock_status(),
		);
	}

	/**
	 * The exact count of orders (any status) created in the trailing 24
	 * hours, or null if the matching set exceeds SAFE_PROCESSING_CAP — the
	 * caller renders the fixed "too many matching orders" acknowledgement
	 * in that case, never a truncated or lower-bound count. Never loads
	 * order objects: a count-only probe is sufficient.
	 *
	 * @return int|null
	 */
	public function recent_order_count(): ?int {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return null;
		}

		$until = time();
		$since = $until - DAY_IN_SECONDS;

		$total = $this->count_only_probe( array(), $since, $until );

		return self::is_within_safe_cap( $total ) ? $total : null;
	}

	/**
	 * Order count and gross total (sum of get_total()) for
	 * completed+processing orders in a fixed window, computed only via the
	 * bounded paged-fetch strategy. Returns null when the matching set
	 * exceeds SAFE_PROCESSING_CAP — the caller renders the fixed
	 * cap-exceeded acknowledgement in that case, never a partial count or
	 * total.
	 *
	 * @param string $window One of 'today', 'week', 'month' — pre-validated by CommandParser.
	 *
	 * @return array{count: int, gross_total: float}|null
	 */
	public function sales_summary( string $window ): ?array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return null;
		}

		list( $since, $until ) = $this->window_bounds( $window );
		$statuses               = array( 'completed', 'processing' );

		$total = $this->count_only_probe( $statuses, $since, $until );

		if ( ! self::is_within_safe_cap( $total ) ) {
			return null;
		}

		if ( 0 === $total ) {
			return array(
				'count'       => 0,
				'gross_total' => 0.0,
			);
		}

		$pages       = self::pages_needed( $total );
		$gross_total = 0.0;
		$counted     = 0;

		for ( $page = 1; $page <= $pages; $page++ ) {
			$orders = wc_get_orders(
				array(
					'status'       => $statuses,
					'date_created' => $since . '...' . $until,
					'limit'        => self::PAGE_SIZE,
					'page'         => $page,
					'return'       => 'objects',
				)
			);

			foreach ( $orders as $order ) {
				$gross_total += (float) $order->get_total();
				++$counted;

				// Defensive belt-and-suspenders: the store's data changed
				// between the count probe and this fetch — abort rather
				// than ever return a partial sum as if it were complete.
				if ( ! self::is_within_safe_cap( $counted ) ) {
					return null;
				}
			}
		}

		return array(
			'count'       => $counted,
			'gross_total' => $gross_total,
		);
	}

	/**
	 * The cheap, count-only `'paginate' => true` probe both `/orders` and
	 * `/sales` rely on before ever loading an order object — a bounded-cost
	 * `COUNT`-shaped query regardless of how large the matching set is.
	 *
	 * @param array<int, string> $statuses Order statuses to match, or an empty array for "any status".
	 * @param int                $since    Range start, Unix timestamp.
	 * @param int                $until    Range end, Unix timestamp.
	 *
	 * @return int
	 */
	private function count_only_probe( array $statuses, int $since, int $until ): int {
		$args = array(
			'date_created' => $since . '...' . $until,
			'limit'        => self::PAGE_SIZE,
			'paginate'     => true,
			'return'       => 'ids',
		);

		if ( array() !== $statuses ) {
			$args['status'] = $statuses;
		}

		$result = wc_get_orders( $args );

		return isset( $result->total ) ? (int) $result->total : 0;
	}

	/**
	 * The fixed timezone rule (M08 plan §4a): `today` is site-local
	 * calendar midnight to now; `week`/`month` are trailing 7-day / 30-day
	 * rolling windows ending now, matching EventHistoryRepository's own
	 * rolling-window convention rather than a calendar week/month.
	 *
	 * @param string $window One of 'today', 'week', 'month'.
	 *
	 * @return array{0: int, 1: int} [since, until], both Unix timestamps.
	 */
	private function window_bounds( string $window ): array {
		$until = time();

		if ( 'today' === $window ) {
			$now      = new DateTimeImmutable( '@' . $until );
			$now      = $now->setTimezone( wp_timezone() );
			$midnight = $now->setTime( 0, 0, 0 );

			return array( $midnight->getTimestamp(), $until );
		}

		$days = 'week' === $window ? 7 : 30;

		return array( $until - ( $days * DAY_IN_SECONDS ), $until );
	}
}
