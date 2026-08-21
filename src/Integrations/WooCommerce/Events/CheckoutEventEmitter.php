<?php
/**
 * WooCommerce checkout-validation-failed event emission.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Integrations\WooCommerce\Events;

use UniversalTelegram\Events\Registry;
use UniversalTelegram\Privacy\Classification;
use WP_Error;

/**
 * Emits woocommerce.checkout_validation_failed, classic (shortcode) checkout
 * only, bound to woocommerce_after_checkout_validation. No block/Store API
 * equivalent hook exists in WooCommerce core as of 11.0.1 — this is a
 * documented, known gap, not an unofficial Store API workaround (M03 plan
 * §5.10, ADR-0018 Decision §8).
 */
final class CheckoutEventEmitter {

	public const CHECKOUT_VALIDATION_FAILED = 'woocommerce.checkout_validation_failed';

	/**
	 * Registers this emitter's event types.
	 *
	 * @param Registry $registry The current request's event registry.
	 */
	public function register_event_types( Registry $registry ): void {
		$fields = array(
			'actor.user_id'           => Classification::INTERNAL,
			'payload.error_codes_csv' => Classification::PUBLIC,
			'context.checkout_type'   => Classification::PUBLIC,
		);

		$registry->register(
			self::CHECKOUT_VALIDATION_FAILED,
			1,
			$fields,
			array_keys( $fields ),
			array( 'payload.error_codes_csv', 'context.checkout_type' )
		);
	}

	/**
	 * Binds this emitter's WooCommerce hook callbacks. Called only when
	 * WooCommerceSupport::is_active() is true (M03 plan §4).
	 */
	public function register_hooks(): void {
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'on_checkout_validation' ), 10, 2 );
	}

	/**
	 * The woocommerce_after_checkout_validation callback. $errors is a
	 * shared, by-reference-mutated WP_Error object; this callback only
	 * reads get_error_codes() and never mutates it, to avoid becoming a
	 * correctness risk in a shared object (M03 plan §5.10).
	 *
	 * @param array<string, mixed> $data   The posted checkout data. Never
	 *                                      read (no checkout body in the
	 *                                      envelope, per M03 plan §5.14).
	 * @param WP_Error             $errors The validation errors. Read only,
	 *                                     never mutated.
	 */
	public function on_checkout_validation( array $data, WP_Error $errors ): void {
		$error_codes = $errors->get_error_codes();
		if ( empty( $error_codes ) ) {
			return;
		}

		// EventEnvelope's fail-closed field-classification validation walks
		// every array value recursively, requiring a classification-map
		// entry at each leaf's own dot-path — incompatible with a
		// variable-length list of scalars. Following this codebase's own
		// established precedent for the same shape of problem
		// (UserLifecycleEmitter's payload.old_roles_csv), the error-code
		// list is joined into one comma-separated string field rather than
		// registering an array (M03 plan's payload.error_codes field is
		// implemented as payload.error_codes_csv; see closure record for
		// this documented deviation).
		$emit_data = array(
			'actor'   => array(
				'user_id' => get_current_user_id(),
			),
			'context' => array(
				'checkout_type' => 'classic',
			),
			'payload' => array(
				'error_codes_csv' => implode( ',', $error_codes ),
			),
		);

		$key = 'checkout_validation:' . wp_hash( wp_json_encode( $error_codes ) ) . ':' . get_current_user_id() . ':' . floor( time() / 5 );

		universal_telegram_emit_event( self::CHECKOUT_VALIDATION_FAILED, $emit_data, $key );
	}
}
