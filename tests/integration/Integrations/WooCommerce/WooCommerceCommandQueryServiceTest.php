<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Integrations\WooCommerce;

use UniversalTelegram\Integrations\WooCommerce\WooCommerceCommandQueryService;
use WP_UnitTestCase;

/**
 * M08 WP5: bounded, read-only WooCommerce queries backing /orders, /order,
 * /stock, /sales. Every cap-related assertion mocks the count-only probe's
 * result rather than materializing 500+ real orders.
 */
final class WooCommerceCommandQueryServiceTest extends WP_UnitTestCase {

	private WooCommerceCommandQueryService $service;

	protected function setUp(): void {
		parent::setUp();

		if ( ! getenv( 'UT_TEST_WC_ACTIVE' ) ) {
			$this->markTestSkipped( 'WooCommerce is not active in this configuration.' );
		}

		$this->service = new WooCommerceCommandQueryService();
	}

	private function create_order( string $status, float $total ): \WC_Order {
		$order = wc_create_order();
		$order->set_currency( 'USD' );
		$order->set_status( $status );
		$order->set_total( $total );
		$order->save();

		return $order;
	}

	private function create_product( string $sku, bool $manage_stock, ?int $quantity, string $stock_status ): \WC_Product_Simple {
		$product = new \WC_Product_Simple();
		$product->set_name( 'M08 Test Product' );
		$product->set_sku( $sku );
		$product->set_manage_stock( $manage_stock );

		if ( $manage_stock && null !== $quantity ) {
			$product->set_stock_quantity( $quantity );
		}

		$product->set_stock_status( $stock_status );
		$product->save();

		return $product;
	}

	public function test_order_summary_returns_only_the_fixed_field_set(): void {
		$order = $this->create_order( 'processing', 42.50 );

		$summary = $this->service->order_summary( $order->get_id() );

		$this->assertNotNull( $summary );
		$this->assertSame( array( 'status', 'date_created', 'currency', 'total', 'item_count' ), array_keys( $summary ) );
		$this->assertSame( 'processing', $summary['status'] );
		$this->assertSame( 'USD', $summary['currency'] );
		$this->assertEquals( 42.50, (float) $summary['total'] );
		$this->assertSame( 0, $summary['item_count'] );
	}

	public function test_order_summary_for_a_nonexistent_id_is_null(): void {
		$this->assertNull( $this->service->order_summary( 999999999 ) );
	}

	public function test_stock_summary_returns_only_the_fixed_field_set_and_never_the_sku(): void {
		$sku     = 'M08-STOCK-' . wp_generate_password( 6, false );
		$product = $this->create_product( $sku, true, 7, 'instock' );

		$summary = $this->service->stock_summary( $sku );

		$this->assertNotNull( $summary );
		$this->assertSame( array( 'name', 'manages_stock', 'stock_quantity', 'stock_status' ), array_keys( $summary ) );
		$this->assertSame( 'M08 Test Product', $summary['name'] );
		$this->assertTrue( $summary['manages_stock'] );
		$this->assertSame( 7, $summary['stock_quantity'] );
		$this->assertSame( 'instock', $summary['stock_status'] );
		$this->assertStringNotContainsString( $sku, wp_json_encode( $summary ) );
	}

	public function test_stock_summary_omits_quantity_when_not_managed(): void {
		$sku = 'M08-STOCK-' . wp_generate_password( 6, false );
		$this->create_product( $sku, false, null, 'instock' );

		$summary = $this->service->stock_summary( $sku );

		$this->assertNotNull( $summary );
		$this->assertFalse( $summary['manages_stock'] );
		$this->assertNull( $summary['stock_quantity'] );
	}

	public function test_stock_summary_for_a_nonexistent_sku_is_null(): void {
		$this->assertNull( $this->service->stock_summary( 'no-such-sku-exists' ) );
	}

	public function test_recent_order_count_counts_only_the_trailing_24_hours(): void {
		$in_window = $this->create_order( 'processing', 10 );
		$in_window->set_date_created( time() - 3600 );
		$in_window->save();

		$outside_window = $this->create_order( 'processing', 10 );
		$outside_window->set_date_created( time() - ( 25 * HOUR_IN_SECONDS ) );
		$outside_window->save();

		$count = $this->service->recent_order_count();

		$this->assertNotNull( $count );
		$this->assertGreaterThanOrEqual( 1, $count );
	}

	public function test_sales_summary_sums_only_completed_and_processing_orders_in_the_window(): void {
		$this->create_order( 'completed', 20.00 );
		$this->create_order( 'processing', 30.00 );
		$this->create_order( 'pending', 999.00 ); // Must not be counted.

		$summary = $this->service->sales_summary( 'today' );

		$this->assertNotNull( $summary );
		$this->assertSame( 2, $summary['count'] );
		$this->assertEquals( 50.00, $summary['gross_total'] );
	}

	public function test_sales_summary_today_uses_site_local_midnight(): void {
		$too_early = $this->create_order( 'completed', 5.00 );
		// Two days ago — well before today's site-local midnight.
		$too_early->set_date_created( time() - ( 2 * DAY_IN_SECONDS ) );
		$too_early->save();

		$summary = $this->service->sales_summary( 'today' );

		$this->assertNotNull( $summary );
		$this->assertSame( 0, $summary['count'] );
	}
}
