<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Integrations\WooCommerce\Events;

use ReflectionProperty;
use Throwable;
use UniversalTelegram\Core\Plugin;
use UniversalTelegram\Events\EventDispatcher;
use UniversalTelegram\Events\EventEmitter;
use UniversalTelegram\Events\EventEnvelope;
use WP_UnitTestCase;

/**
 * Demonstrates the charter's "Telegram failures cannot affect checkout"
 * constraint at its actual mechanism: EventEmitter::emit()'s existing
 * never-throws contract (M02, unchanged) wraps the entire downstream call
 * graph. This is a property of emit() itself, not of any individual
 * emitter's callback logic (M03 plan §8, WP7) — exercised here end to end,
 * fired from a real WooCommerce hook callback via the plugin's own global
 * composition-root instance.
 *
 * The forced downstream failure is injected by temporarily substituting the
 * global Plugin instance's private EventEmitter with one wired to a
 * dispatcher that always throws — the exact same technique M02's own
 * EventEmitterTest uses (an EventDispatcher subclass that throws), applied
 * here to the live singleton for the duration of one WooCommerce hook call
 * only, then restored. Deliberately avoids any DDL statement (e.g. RENAME/
 * ALTER TABLE) to simulate the failure: MySQL DDL causes an implicit
 * COMMIT, which would break WP_UnitTestCase's transaction-rollback test
 * isolation for every later test in the same run.
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

	/**
	 * Swaps the live Plugin singleton's EventEmitter for one wired to an
	 * always-throwing EventDispatcher, runs $callback, then restores the
	 * original EventEmitter unconditionally.
	 */
	private function with_forced_downstream_failure( callable $callback ): void {
		$plugin = Plugin::instance();

		$property = new ReflectionProperty( Plugin::class, 'event_emitter' );
		$property->setAccessible( true );
		$original_emitter = $property->getValue( $plugin );

		$registry_property = new ReflectionProperty( Plugin::class, 'event_registry' );
		$registry_property->setAccessible( true );
		$registry = $registry_property->getValue( $plugin );

		$audit_property = new ReflectionProperty( Plugin::class, 'audit_logger' );
		$audit_property->setAccessible( true );
		$audit = $audit_property->getValue( $plugin );

		$throwing_dispatcher = new class( $registry ) extends EventDispatcher {
			public function __construct( $registry ) {
				// Intentionally does not call the parent constructor: this
				// double never delegates to real history/rule-evaluation
				// collaborators, it only proves emit() survives a throw.
				unset( $registry );
			}

			public function handle( EventEnvelope $event ): void {
				throw new \RuntimeException( 'M03 CheckoutSafetyTest: simulated downstream failure.' );
			}
		};

		$broken_emitter = new EventEmitter( $registry, $throwing_dispatcher, $audit );
		$property->setValue( $plugin, $broken_emitter );

		try {
			$callback();
		} finally {
			$property->setValue( $plugin, $original_emitter );
		}
	}

	public function test_a_forced_event_dispatch_failure_never_propagates_out_of_a_real_woocommerce_hook(): void {
		$order     = $this->create_order();
		$exception = null;

		$this->with_forced_downstream_failure(
			function () use ( $order, &$exception ) {
				try {
					do_action( 'woocommerce_checkout_order_processed', $order->get_id(), array(), $order );
				} catch ( Throwable $e ) {
					$exception = $e;
				}
			}
		);

		$this->assertNull( $exception, 'A downstream event-dispatch failure must never propagate out of a WooCommerce hook callback.' );
	}

	public function test_a_forced_event_dispatch_failure_never_propagates_out_of_the_stock_hook(): void {
		$product = new \WC_Product_Simple();
		$product->set_name( 'M03 checkout-safety product' );
		$product->set_regular_price( '1.00' );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 0 );
		$product->save();

		$exception = null;

		$this->with_forced_downstream_failure(
			function () use ( $product, &$exception ) {
				try {
					do_action( 'woocommerce_no_stock', $product );
				} catch ( Throwable $e ) {
					$exception = $e;
				}
			}
		);

		$this->assertNull( $exception );
	}

	public function test_a_real_order_created_hook_completes_normally_and_is_recorded(): void {
		global $wpdb;

		$table = $wpdb->prefix . \UniversalTelegram\Persistence\Migrator::EVENT_HISTORY_TABLE;
		$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		$order = $this->create_order();
		do_action( 'woocommerce_checkout_order_processed', $order->get_id(), array(), $order );

		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE event_type = %s", 'woocommerce.order_created' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertSame( 1, $count, 'Sanity check: the real (unbroken) global emission path must still work end to end.' );
	}
}
