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

	/** Buttons per page in the `/stock` inline-keyboard menu (products or variations). */
	public const MENU_PAGE_SIZE = 8;

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
	 * @return array{status: string, date_created: string, currency: string, total: float, item_count: int}|null
	 *               Null when not found, not retrievable, or not a normal (non-refund) order object — the caller
	 *               renders the identical "not found or unavailable" text regardless of cause.
	 */
	public function order_summary( int $order_id ): ?array {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return null;
		}

		$order = wc_get_order( $order_id );

		// Not-found, a WP_Error-shaped failure, or any non-order object
		// (the charter's own "not a normal order object" case) — all
		// covered by requiring a genuine WC_Order instance.
		if ( ! $order instanceof \WC_Order ) {
			return null;
		}

		$date_created      = $order->get_date_created();
		$date_created_text = null !== $date_created ? wp_date( 'Y-m-d H:i', $date_created->getTimestamp() ) : '';

		return array(
			'status'       => $order->get_status(),
			'date_created' => false !== $date_created_text ? $date_created_text : '',
			'currency'     => $order->get_currency(),
			'total'        => (float) $order->get_total(),
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

		if ( ! $product instanceof \WC_Product ) {
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
	 * A single product's or variation's fixed, narrow stock field set,
	 * looked up by its own post id rather than a SKU — the id is used only
	 * as a lookup key, never included in the return value. Backs the
	 * `/stock` button-menu drill-down (a tapped inline-keyboard button
	 * carries an id, not a SKU).
	 *
	 * @param int $item_id The requested product or variation id.
	 *
	 * @return array{name: string, manages_stock: bool, stock_quantity: int|null, stock_status: string}|null
	 *               Null when no matching product/variation exists or it is not retrievable.
	 */
	public function stock_summary_by_id( int $item_id ): ?array {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return null;
		}

		$product = wc_get_product( $item_id );

		if ( ! $product instanceof \WC_Product ) {
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
	 * A bounded, paginated page of top-level purchasable items for the
	 * `/stock` button menu: published simple products (leaf — tapping
	 * shows stock directly) and published variable products (a branch —
	 * tapping lists their variations). Grouped and external products are
	 * excluded, matching `PO_Product_Validator`-style purchasable-item
	 * scoping used elsewhere in this catalog's tooling. Ordered by title
	 * for a stable, predictable page sequence.
	 *
	 * @param int $page 1-based page number.
	 *
	 * @return array{items: array<int, array{id:int,name:string,has_variations:bool}>, total_pages: int}
	 */
	public function list_stock_menu_items( int $page ): array {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return array(
				'items'       => array(),
				'total_pages' => 1,
			);
		}

		$page = max( 1, $page );

		$query = wc_get_products(
			array(
				'status'   => 'publish',
				'type'     => array( 'simple', 'variable' ),
				'orderby'  => 'title',
				'order'    => 'ASC',
				'limit'    => self::MENU_PAGE_SIZE,
				'page'     => $page,
				'paginate' => true,
				'return'   => 'objects',
			)
		);

		$products = $query->products ?? array();
		$total    = isset( $query->total ) ? (int) $query->total : 0;

		$items = array();

		foreach ( $products as $product ) {
			if ( ! $product instanceof \WC_Product ) {
				continue;
			}

			$items[] = array(
				'id'             => $product->get_id(),
				'name'           => $product->get_name(),
				'has_variations' => $product->is_type( 'variable' ),
			);
		}

		return array(
			'items'       => $items,
			'total_pages' => max( 1, (int) ceil( $total / self::MENU_PAGE_SIZE ) ),
		);
	}

	/**
	 * A bounded, paginated page of a variable product's own variations,
	 * for the `/stock` button menu's drill-down level. Null when the
	 * parent id does not resolve to a published variable product — the
	 * caller renders the identical "not found" acknowledgement regardless
	 * of cause.
	 *
	 * @param int $parent_id The variable product's own id.
	 * @param int $page      1-based page number.
	 *
	 * @return array{parent_name: string, items: array<int, array{id:int,label:string}>, total_pages: int}|null
	 */
	public function list_stock_menu_variations( int $parent_id, int $page ): ?array {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return null;
		}

		$parent = wc_get_product( $parent_id );

		if ( ! $parent instanceof \WC_Product || ! $parent->is_type( 'variable' ) ) {
			return null;
		}

		$page = max( 1, $page );

		/**
		 * WC_Product_Variable::get_children() returns every child
		 * variation id, already bounded by the catalog's own realistic
		 * variation counts (never a store-wide unbounded query) — sliced
		 * in-memory rather than re-queried per page, since a single
		 * product's own variation count is never large enough to matter.
		 *
		 * @var array<int, int> $all_ids
		 */
		$all_ids  = $parent instanceof \WC_Product_Variable ? $parent->get_children() : array();
		$total    = count( $all_ids );
		$offset   = ( $page - 1 ) * self::MENU_PAGE_SIZE;
		$page_ids = array_slice( $all_ids, $offset, self::MENU_PAGE_SIZE );

		$items = array();

		foreach ( $page_ids as $variation_id ) {
			$variation = wc_get_product( $variation_id );

			if ( ! $variation instanceof \WC_Product_Variation ) {
				continue;
			}

			$label = wc_get_formatted_variation( $variation, true, false );
			$label = '' !== $label ? $label : ( '#' . $variation_id );

			$items[] = array(
				'id'    => $variation_id,
				'label' => $label,
			);
		}

		return array(
			'parent_name' => $parent->get_name(),
			'items'       => $items,
			'total_pages' => max( 1, (int) ceil( $total / self::MENU_PAGE_SIZE ) ),
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
		$statuses              = array( 'completed', 'processing' );

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
