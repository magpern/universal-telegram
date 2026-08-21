<?php
/**
 * WooCommerce coupon event emission.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Integrations\WooCommerce\Events;

use UniversalTelegram\Events\Registry;
use UniversalTelegram\Privacy\Classification;
use WC_Coupon;

/**
 * Emits woocommerce.coupon_applied and woocommerce.coupon_rejected, sourced from
 * woocommerce_applied_coupon (an action) and woocommerce_coupon_error (a
 * filter) respectively (M03 plan §5.12–§5.13). Both keys use coarse
 * 5-second time-bucket coalescing (M03 plan §5.2): no more precise, stable,
 * pre-write identity is available from WooCommerce core for these
 * occurrences.
 */
final class CouponEventEmitter {

	public const COUPON_APPLIED  = 'woocommerce.coupon_applied';
	public const COUPON_REJECTED = 'woocommerce.coupon_rejected';

	/**
	 * Registers this emitter's event types.
	 *
	 * @param Registry $registry The current request's event registry.
	 */
	public function register_event_types( Registry $registry ): void {
		$applied_fields = array(
			'actor.user_id'       => Classification::INTERNAL,
			'subject.coupon_code' => Classification::PUBLIC,
			'payload.cart_total'  => Classification::PUBLIC,
		);
		$registry->register(
			self::COUPON_APPLIED,
			1,
			$applied_fields,
			array_keys( $applied_fields ),
			array( 'subject.coupon_code', 'payload.cart_total' )
		);

		$rejected_fields = array(
			'actor.user_id'       => Classification::INTERNAL,
			'subject.coupon_code' => Classification::PUBLIC,
			'payload.error_code'  => Classification::PUBLIC,
		);
		$registry->register(
			self::COUPON_REJECTED,
			1,
			$rejected_fields,
			array_keys( $rejected_fields ),
			array( 'subject.coupon_code', 'payload.error_code' )
		);
	}

	/**
	 * Binds this emitter's WooCommerce hook callbacks. Called only when
	 * WooCommerceSupport::is_active() is true (M03 plan §4).
	 */
	public function register_hooks(): void {
		add_action( 'woocommerce_applied_coupon', array( $this, 'on_applied_coupon' ), 10, 1 );
		add_filter( 'woocommerce_coupon_error', array( $this, 'on_coupon_error' ), 10, 3 );
	}

	/**
	 * The woocommerce_applied_coupon callback.
	 *
	 * @param string $coupon_code The applied coupon code.
	 */
	public function on_applied_coupon( string $coupon_code ): void {
		if ( null === WC()->cart || null === WC()->session ) {
			return;
		}

		$data = array(
			'actor'   => array(
				'user_id' => get_current_user_id(),
			),
			'subject' => array(
				'coupon_code' => $coupon_code,
			),
			'payload' => array(
				'cart_total' => (float) WC()->cart->get_total( 'edit' ),
			),
		);

		$key = 'coupon_applied:' . WC()->session->get_customer_id() . ':' . strtolower( $coupon_code ) . ':' . floor( time() / 5 );

		universal_telegram_emit_event( self::COUPON_APPLIED, $data, $key );
	}

	/**
	 * The woocommerce_coupon_error filter callback. Registered via
	 * add_filter and returns $message unmodified — EventEmitter::emit() is
	 * synchronous and non-throwing, so calling it from a filter callback is
	 * behaviorally no different from an action callback, and WC's own error
	 * display is unaffected (M03 plan §5.13, ADR-0018).
	 *
	 * @param string          $message    The error message. Read only to
	 *                                     return unmodified; never placed
	 *                                     in the envelope.
	 * @param int|string      $error_code WC's own stable coupon-error constant.
	 * @param WC_Coupon|mixed $coupon     The coupon, if resolvable.
	 *
	 * @return string $message, unmodified.
	 */
	public function on_coupon_error( string $message, $error_code, $coupon = null ): string {
		if ( null === WC()->session ) {
			return $message;
		}

		$coupon_code = '';
		if ( $coupon instanceof WC_Coupon ) {
			$coupon_code = $coupon->get_code();
		} elseif ( is_string( $coupon ) ) {
			$coupon_code = $coupon;
		}

		$data = array(
			'actor'   => array(
				'user_id' => get_current_user_id(),
			),
			'subject' => array(
				'coupon_code' => $coupon_code,
			),
			'payload' => array(
				'error_code' => (int) $error_code,
			),
		);

		$key = 'coupon_rejected:' . WC()->session->get_customer_id() . ':' . (int) $error_code . ':' . floor( time() / 5 );

		universal_telegram_emit_event( self::COUPON_REJECTED, $data, $key );

		return $message;
	}
}
