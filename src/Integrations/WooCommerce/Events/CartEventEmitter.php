<?php
/**
 * WooCommerce cart event emission.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Integrations\WooCommerce\Events;

use UniversalTelegram\Events\Registry;
use UniversalTelegram\Privacy\Classification;

/**
 * woocommerce.cart_item_added, sourced from woocommerce_add_to_cart, shared
 * identically between classic and Cart-block checkout (M03 plan §5.11).
 * Uses line-identity coalescing: the idempotency key contains no quantity
 * or time component, so every add-to-cart call for the same cart line
 * collapses to one event, and the recorded fields reflect the state at the
 * first such call, never a later one.
 */
final class CartEventEmitter {

	public const CART_ITEM_ADDED = 'woocommerce.cart_item_added';

	/**
	 * Registers this emitter's event types.
	 *
	 * @param Registry $registry The current request's event registry.
	 */
	public function register_event_types( Registry $registry ): void {
		$fields = array(
			'actor.user_id'        => Classification::INTERNAL,
			'subject.product_id'   => Classification::PUBLIC,
			'payload.quantity'     => Classification::PUBLIC,
			'payload.variation_id' => Classification::PUBLIC,
			'payload.cart_total'   => Classification::PUBLIC,
			'payload.currency'     => Classification::PUBLIC,
		);

		$registry->register(
			self::CART_ITEM_ADDED,
			1,
			$fields,
			array_keys( $fields ),
			array( 'subject.product_id', 'payload.quantity', 'payload.variation_id', 'payload.cart_total', 'payload.currency' )
		);
	}

	/**
	 * Binds this emitter's WooCommerce hook callbacks. Called only when
	 * WooCommerceSupport::is_active() is true (M03 plan §4).
	 */
	public function register_hooks(): void {
		add_action( 'woocommerce_add_to_cart', array( $this, 'on_add_to_cart' ), 10, 6 );
	}

	/**
	 * The woocommerce_add_to_cart callback. Confirmed (WooCommerce 11.0.1
	 * Store API source) to fire identically for classic and Cart-block
	 * checkout, so no dual-hook dedup concern exists for this type (M03
	 * plan §5.11).
	 *
	 * @param string               $cart_item_key  WooCommerce's own deterministic hash of product+variation+cart-item-data.
	 * @param int                  $product_id     The product id.
	 * @param int                  $quantity       The added quantity.
	 * @param int                  $variation_id   The variation id, 0 if none.
	 * @param array<string, mixed> $variation      Variation attribute data. Unused.
	 * @param array<string, mixed> $cart_item_data Arbitrary cart item data. Unused.
	 */
	public function on_add_to_cart( string $cart_item_key, int $product_id, int $quantity, int $variation_id = 0, array $variation = array(), array $cart_item_data = array() ): void {
		if ( null === WC()->cart || null === WC()->session ) {
			return;
		}

		$data = array(
			'actor'   => array(
				'user_id' => get_current_user_id(),
			),
			'subject' => array(
				'product_id' => $product_id,
			),
			'payload' => array(
				'quantity'     => $quantity,
				'variation_id' => $variation_id,
				'cart_total'   => (float) WC()->cart->get_total( 'edit' ),
				'currency'     => get_woocommerce_currency(),
			),
		);

		$key = 'cart:' . WC()->session->get_customer_id() . ':' . $cart_item_key;

		universal_telegram_emit_event( self::CART_ITEM_ADDED, $data, $key );
	}
}
