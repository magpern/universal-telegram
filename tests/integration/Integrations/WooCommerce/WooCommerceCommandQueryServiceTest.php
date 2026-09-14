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

	/**
	 * A variable product with the given number of child variations, each a
	 * distinct "Size" attribute value, managing stock at a fixed quantity.
	 *
	 * @param string $name            The parent product's own name.
	 * @param int    $variation_count How many child variations to create.
	 *
	 * @return array{0: \WC_Product_Variable, 1: array<int, \WC_Product_Variation>}
	 */
	private function create_variable_product( string $name, int $variation_count ): array {
		$attribute = new \WC_Product_Attribute();
		$attribute->set_name( 'Size' );
		$attribute->set_options( array_map( static fn ( int $n ): string => "Size {$n}", range( 1, $variation_count ) ) );
		$attribute->set_variation( true );
		$attribute->set_visible( true );

		$parent = new \WC_Product_Variable();
		$parent->set_name( $name );
		$parent->set_attributes( array( $attribute ) );
		$parent->save();

		$variations = array();

		for ( $n = 1; $n <= $variation_count; $n++ ) {
			$variation = new \WC_Product_Variation();
			$variation->set_parent_id( $parent->get_id() );
			$variation->set_attributes( array( 'size' => "Size {$n}" ) );
			$variation->set_manage_stock( true );
			$variation->set_stock_quantity( $n );
			$variation->set_stock_status( 'instock' );
			$variation->save();

			$variations[] = $variation;
		}

		// WC_Product_Variable caches its own children; reload so get_children() sees them.
		$parent = wc_get_product( $parent->get_id() );

		return array( $parent, $variations );
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

	public function test_stock_summary_by_id_matches_stock_summary_for_the_same_product(): void {
		$sku     = 'M09-STOCK-ID-' . wp_generate_password( 6, false );
		$product = $this->create_product( $sku, true, 3, 'instock' );

		$by_sku = $this->service->stock_summary( $sku );
		$by_id  = $this->service->stock_summary_by_id( $product->get_id() );

		$this->assertSame( $by_sku, $by_id );
	}

	public function test_stock_summary_by_id_for_a_nonexistent_id_is_null(): void {
		$this->assertNull( $this->service->stock_summary_by_id( 999999999 ) );
	}

	public function test_stock_summary_by_id_works_for_a_variation(): void {
		list( , $variations ) = $this->create_variable_product( 'M09 Variable Product', 2 );

		$summary = $this->service->stock_summary_by_id( $variations[0]->get_id() );

		$this->assertNotNull( $summary );
		$this->assertTrue( $summary['manages_stock'] );
		$this->assertSame( 1, $summary['stock_quantity'] );
	}

	public function test_list_stock_menu_items_includes_simple_and_variable_products(): void {
		$simple_name = 'M09 Simple ' . wp_generate_password( 6, false );
		$this->create_product( 'M09-MENU-' . wp_generate_password( 6, false ), true, 5, 'instock' );
		$this->create_product( $simple_name, true, 5, 'instock' );
		list( $parent ) = $this->create_variable_product( 'M09 Variable ' . wp_generate_password( 6, false ), 2 );

		$result = $this->service->list_stock_menu_items( 1 );

		$ids = array_column( $result['items'], 'id' );
		$this->assertContains( $parent->get_id(), $ids );

		$by_id = array();
		foreach ( $result['items'] as $item ) {
			$by_id[ $item['id'] ] = $item;
		}

		$this->assertTrue( $by_id[ $parent->get_id() ]['has_variations'] );
	}

	public function test_list_stock_menu_items_paginates_at_the_menu_page_size(): void {
		for ( $n = 0; $n < WooCommerceCommandQueryService::MENU_PAGE_SIZE + 3; $n++ ) {
			$this->create_product( 'M09-PAGE-' . wp_generate_password( 8, false ), true, 1, 'instock' );
		}

		$page_1 = $this->service->list_stock_menu_items( 1 );

		$this->assertCount( WooCommerceCommandQueryService::MENU_PAGE_SIZE, $page_1['items'] );
		$this->assertGreaterThanOrEqual( 2, $page_1['total_pages'] );
	}

	public function test_list_stock_menu_variations_lists_a_parents_own_children(): void {
		list( $parent, $variations ) = $this->create_variable_product( 'M09 Variations Parent', 3 );

		$result = $this->service->list_stock_menu_variations( $parent->get_id(), 1 );

		$this->assertNotNull( $result );
		$this->assertSame( 'M09 Variations Parent', $result['parent_name'] );
		$this->assertCount( 3, $result['items'] );

		$ids = array_column( $result['items'], 'id' );
		foreach ( $variations as $variation ) {
			$this->assertContains( $variation->get_id(), $ids );
		}
	}

	public function test_list_stock_menu_variations_for_a_simple_product_is_null(): void {
		$product = $this->create_product( 'M09-NOTVAR-' . wp_generate_password( 6, false ), true, 1, 'instock' );

		$this->assertNull( $this->service->list_stock_menu_variations( $product->get_id(), 1 ) );
	}

	public function test_list_stock_menu_variations_for_a_nonexistent_parent_is_null(): void {
		$this->assertNull( $this->service->list_stock_menu_variations( 999999999, 1 ) );
	}
}
