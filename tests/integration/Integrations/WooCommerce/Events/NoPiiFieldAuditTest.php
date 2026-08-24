<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Integrations\WooCommerce\Events;

use UniversalTelegram\Core\Plugin;
use WP_UnitTestCase;

/**
 * Catalog-wide denylist scan of every woocommerce.* event type's
 * classification-map field paths, consolidating the per-emitter spot checks
 * into one cross-cutting proof (M03 plan §5.14, §8 WP7). No exceptions:
 * the catalog carries no payment_method, gateway-identifying, or
 * customer-PII field of any kind at any classification level.
 */
final class NoPiiFieldAuditTest extends WP_UnitTestCase {

	private const DENYLIST_SUBSTRINGS = array(
		'email',
		'phone',
		'address',
		'billing_',
		'shipping_',
		'token',
		'payment_method',
		'gateway',
		'card_',
		'name',
		'ip_address',
		'transaction_id',
	);

	// context.has_transaction_id is the one explicitly permitted
	// payment-adjacent field (a boolean, never the id itself) — M03 plan
	// §5.5, §5.14. payload.product_name is a WooCommerce catalog product's
	// own name — public, non-personal, the same sensitivity class as the
	// already-uncatalogued payload.product_sku on the same event type — not
	// a customer name; the denylist's "name" substring exists to catch
	// customer/billing/shipping name fields (M08.1 friendly-cart-notification
	// follow-up).
	private const ALLOWED_EXCEPTIONS = array(
		'context.has_transaction_id',
		'payload.product_name',
	);

	public function test_no_woocommerce_event_type_field_path_matches_the_pii_denylist(): void {
		$registry = Plugin::instance()->event_registry();
		$this->assertNotNull( $registry );

		$woocommerce_types = array_filter(
			$registry->all(),
			static function ( array $entry ) {
				return str_starts_with( $entry['event_type'], 'woocommerce.' );
			}
		);

		if ( ! getenv( 'UT_TEST_WC_ACTIVE' ) ) {
			$this->assertCount( 0, $woocommerce_types );
			return;
		}

		$this->assertCount( 11, $woocommerce_types, 'Exactly 11 woocommerce.* event types are expected (M03 plan §5.1).' );

		foreach ( $woocommerce_types as $entry ) {
			$map = $registry->classification_map_for( $entry['event_type'] );

			foreach ( array_keys( $map ) as $path ) {
				if ( in_array( $path, self::ALLOWED_EXCEPTIONS, true ) ) {
					continue;
				}

				foreach ( self::DENYLIST_SUBSTRINGS as $needle ) {
					$this->assertStringNotContainsStringIgnoringCase(
						$needle,
						$path,
						sprintf( 'Event type "%s" field "%s" matches denylisted substring "%s".', $entry['event_type'], $path, $needle )
					);
				}
			}
		}
	}

	public function test_no_payment_method_field_appears_on_any_order_lifecycle_type(): void {
		$registry = Plugin::instance()->event_registry();
		$this->assertNotNull( $registry );

		foreach ( array( 'woocommerce.order_created', 'woocommerce.payment_completed', 'woocommerce.order_failed' ) as $type ) {
			$map = $registry->classification_map_for( $type );
			$this->assertArrayNotHasKey( 'payload.payment_method', $map );
		}
	}
}
