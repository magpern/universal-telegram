<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Integrations\WooCommerce\Events;

use Throwable;
use UniversalTelegram\Persistence\Migrator;
use WP_UnitTestCase;

/**
 * Demonstrates the charter's "Telegram failures cannot affect checkout"
 * constraint at its actual mechanism: EventEmitter::emit()'s existing
 * never-throws contract (M02, unchanged) wraps the entire downstream call
 * graph. This is a property of emit() itself, not of any individual
 * emitter's callback logic (M03 plan §8, WP7) — exercised here end to end,
 * fired from a real WooCommerce hook callback via the plugin's own global
 * composition-root instance, with a forced downstream persistence failure.
 */
final class CheckoutSafetyTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();

		if ( ! getenv( 'UT_TEST_WC_ACTIVE' ) ) {
			$this->markTestSkipped( 'WooCommerce is not active in this configuration.' );
		}
	}

	private function create_order(): \WC_Order {
		$order = wc_create_order();
		$order->set_currency( 'USD' );
		$order->calculate_totals();
		$order->save();

		return $order;
	}

	public function test_a_forced_event_history_persistence_failure_never_propagates_out_of_a_real_woocommerce_hook(): void {
		global $wpdb;

		$table        = $wpdb->prefix . Migrator::EVENT_HISTORY_TABLE;
		$broken_table = $table . '_m03_checkout_safety_test';
		$order        = $this->create_order();
		$exception    = null;

		// Force a downstream persistence failure by renaming the event
		// history table away, mid-request, before the hook fires.
		$wpdb->query( "RENAME TABLE {$table} TO {$broken_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		try {
			do_action( 'woocommerce_checkout_order_processed', $order->get_id(), array(), $order );
		} catch ( Throwable $e ) {
			$exception = $e;
		} finally {
			$wpdb->query( "RENAME TABLE {$broken_table} TO {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		}

		$this->assertNull( $exception, 'A downstream event-history persistence failure must never propagate out of a WooCommerce hook callback.' );
	}

	public function test_a_forced_persistence_failure_never_propagates_out_of_the_stock_hook(): void {
		global $wpdb;

		$table        = $wpdb->prefix . Migrator::EVENT_HISTORY_TABLE;
		$broken_table = $table . '_m03_checkout_safety_test_stock';
		$exception    = null;

		$product = new \WC_Product_Simple();
		$product->set_name( 'M03 checkout-safety product' );
		$product->set_regular_price( '1.00' );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 0 );
		$product->save();

		$wpdb->query( "RENAME TABLE {$table} TO {$broken_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		try {
			do_action( 'woocommerce_no_stock', $product );
		} catch ( Throwable $e ) {
			$exception = $e;
		} finally {
			$wpdb->query( "RENAME TABLE {$broken_table} TO {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		}

		$this->assertNull( $exception );
	}
}
