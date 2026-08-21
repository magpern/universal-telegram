<?php
/**
 * WooCommerce-gated visitor/browser event catalog registration.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Integrations\WooCommerce\Visitor;

use UniversalTelegram\Events\Registry;
use UniversalTelegram\Privacy\Classification;

/**
 * Registers the three WooCommerce-gated visitor.* event types, only wired
 * when WooCommerceSupport::is_active() (M04 plan §4.6, mirroring M03's own
 * conditional-registration pattern, docs/adr/0018). `add_to_cart_intent`
 * is classic-checkout-only; block add-to-cart is explicitly unsupported in
 * M04. `checkout_started_intent` is a page-entry signal, not checkout
 * progress.
 */
final class VisitorCommerceEventCatalog {

	public const PRODUCT_VIEWED          = 'visitor.product_viewed';
	public const ADD_TO_CART_INTENT      = 'visitor.add_to_cart_intent';
	public const CHECKOUT_STARTED_INTENT = 'visitor.checkout_started_intent';

	/**
	 * Registers this catalog's event types.
	 *
	 * @param Registry $registry The current request's event registry.
	 */
	public function register_event_types( Registry $registry ): void {
		$registry->register(
			self::PRODUCT_VIEWED,
			1,
			array( 'subject.product_id' => Classification::PUBLIC ),
			array( 'subject.product_id' ),
			array( 'subject.product_id' )
		);

		$registry->register(
			self::ADD_TO_CART_INTENT,
			1,
			array( 'subject.product_id' => Classification::PUBLIC ),
			array( 'subject.product_id' ),
			array( 'subject.product_id' )
		);

		$registry->register(
			self::CHECKOUT_STARTED_INTENT,
			1,
			array(),
			array(),
			array()
		);
	}
}
