<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Integrations\WooCommerce;

use UniversalTelegram\Core\Plugin;
use WP_UnitTestCase;

/**
 * Structural proof of M03 plan §4's WooCommerce gating: when WooCommerce is
 * absent, no woocommerce.* event type is ever registered, and the plugin's
 * event registry contains only wordpress.* entries. This is the WP-only
 * side of the guard; run in the WordPress-only CI configuration where the
 * absence actually matters. In a WooCommerce-present configuration, this
 * test instead confirms the affirmative: at least one woocommerce.* type is
 * registered, and the eleven-type/thirteen-binding catalog is complete.
 */
final class StructuralGuardTest extends WP_UnitTestCase {

	private const EXPECTED_TYPES = array(
		'woocommerce.order_created',
		'woocommerce.order_status_changed',
		'woocommerce.payment_completed',
		'woocommerce.order_failed',
		'woocommerce.order_cancelled',
		'woocommerce.refund_created',
		'woocommerce.stock_threshold_crossed',
		'woocommerce.checkout_validation_failed',
		'woocommerce.cart_item_added',
		'woocommerce.coupon_applied',
		'woocommerce.coupon_rejected',
	);

	public function test_woocommerce_absent_registers_zero_woocommerce_event_types(): void {
		if ( getenv( 'UT_TEST_WC_ACTIVE' ) ) {
			$this->markTestSkipped( 'This assertion applies only to the WooCommerce-absent configuration.' );
		}

		$registry = Plugin::instance()->event_registry();
		$this->assertNotNull( $registry );

		foreach ( self::EXPECTED_TYPES as $type ) {
			$this->assertFalse( $registry->is_registered( $type ), sprintf( '"%s" must not be registered when WooCommerce is absent.', $type ) );
		}

		foreach ( $registry->all() as $entry ) {
			$this->assertStringStartsNotWith( 'woocommerce.', $entry['event_type'] );
		}
	}

	public function test_woocommerce_present_registers_the_full_catalog(): void {
		if ( ! getenv( 'UT_TEST_WC_ACTIVE' ) ) {
			$this->markTestSkipped( 'This assertion applies only to the WooCommerce-present configuration.' );
		}

		$registry = Plugin::instance()->event_registry();
		$this->assertNotNull( $registry );

		foreach ( self::EXPECTED_TYPES as $type ) {
			$this->assertTrue( $registry->is_registered( $type ), sprintf( '"%s" must be registered when WooCommerce is present.', $type ) );
		}

		$woocommerce_type_count = 0;
		foreach ( $registry->all() as $entry ) {
			if ( str_starts_with( $entry['event_type'], 'woocommerce.' ) ) {
				++$woocommerce_type_count;
			}
		}

		$this->assertCount( count( self::EXPECTED_TYPES ), array_unique( self::EXPECTED_TYPES ) );
		$this->assertSame( count( self::EXPECTED_TYPES ), $woocommerce_type_count );
	}
}
