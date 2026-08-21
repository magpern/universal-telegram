<?php
/**
 * WooCommerce stock-threshold event emission.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Integrations\WooCommerce\Events;

use UniversalTelegram\Events\Registry;
use UniversalTelegram\Privacy\Classification;
use WC_Product;

/**
 * Emits woocommerce.stock_threshold_crossed, sourced from WooCommerce's low/
 * no-stock notification hooks (M03 plan §5.9). Products/stock are never
 * stored in the orders table, so HPOS enablement has zero effect on this
 * emitter's correctness — no storage-backend branching is needed here.
 */
final class StockEventEmitter {

	public const STOCK_THRESHOLD_CROSSED = 'woocommerce.stock_threshold_crossed';

	private const STATUS_LOW = 'low';
	private const STATUS_OUT = 'out';

	/**
	 * Registers this emitter's event types.
	 *
	 * @param Registry $registry The current request's event registry.
	 */
	public function register_event_types( Registry $registry ): void {
		$fields = array(
			'subject.product_id'     => Classification::PUBLIC,
			'payload.status'         => Classification::PUBLIC,
			'payload.stock_quantity' => Classification::PUBLIC,
			'payload.product_sku'    => Classification::PUBLIC,
		);

		$registry->register(
			self::STOCK_THRESHOLD_CROSSED,
			1,
			$fields,
			array_keys( $fields ),
			array_keys( $fields )
		);
	}

	/**
	 * Binds this emitter's WooCommerce hook callbacks. Called only when
	 * WooCommerceSupport::is_active() is true (M03 plan §4).
	 */
	public function register_hooks(): void {
		add_action( 'woocommerce_low_stock', array( $this, 'on_low_stock' ), 10, 1 );
		add_action( 'woocommerce_no_stock', array( $this, 'on_no_stock' ), 10, 1 );
	}

	/**
	 * The woocommerce_low_stock callback.
	 *
	 * @param WC_Product $product The product.
	 */
	public function on_low_stock( WC_Product $product ): void {
		$this->emit( $product, self::STATUS_LOW );
	}

	/**
	 * The woocommerce_no_stock callback.
	 *
	 * @param WC_Product $product The product.
	 */
	public function on_no_stock( WC_Product $product ): void {
		$this->emit( $product, self::STATUS_OUT );
	}

	/**
	 * Emits woocommerce.stock_threshold_crossed. Both the order-driven
	 * (wc_trigger_stock_change_notifications()) and generic
	 * (wc_trigger_stock_change_actions()) internal WooCommerce code paths
	 * can fire either hook for the same logical occurrence; the idempotency
	 * key is state-keyed on (product_id, status, stock_quantity) — not a
	 * fresh UUID or per-call data — so both collapse to one event
	 * regardless of which code path or how much time separates them (M03
	 * plan §5.2, §5.9; ADR-0018 Decision §4).
	 *
	 * @param WC_Product $product The product.
	 * @param string     $status  "low" or "out".
	 */
	private function emit( WC_Product $product, string $status ): void {
		$quantity = $product->get_stock_quantity();

		$data = array(
			'subject' => array(
				'product_id' => $product->get_id(),
			),
			'payload' => array(
				'status'         => $status,
				'stock_quantity' => null === $quantity ? 0 : (int) $quantity,
				'product_sku'    => (string) $product->get_sku(),
			),
		);

		$key = 'product:' . $product->get_id() . ':' . $status . ':' . ( null === $quantity ? 0 : (int) $quantity );

		universal_telegram_emit_event( self::STOCK_THRESHOLD_CROSSED, $data, $key );
	}
}
