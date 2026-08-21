<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Integrations\WooCommerce\Events;

use UniversalTelegram\Persistence\Migrator;
use WP_UnitTestCase;

final class StockEventEmitterTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();

		if ( ! getenv( 'UT_TEST_WC_ACTIVE' ) ) {
			$this->markTestSkipped( 'WooCommerce is not active in this configuration.' );
		}
	}

	private function truncate_history(): void {
		global $wpdb;
		$table = $wpdb->prefix . Migrator::EVENT_HISTORY_TABLE;
		$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function rows_for_type( string $event_type ): array {
		global $wpdb;
		$table = $wpdb->prefix . Migrator::EVENT_HISTORY_TABLE;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE event_type = %s ORDER BY id ASC", $event_type ), ARRAY_A );
	}

	private function create_product( int $stock_quantity ): \WC_Product_Simple {
		$product = new \WC_Product_Simple();
		$product->set_name( 'M03 stock test product' );
		$product->set_regular_price( '9.99' );
		$product->set_sku( 'M03-STOCK-' . wp_generate_password( 6, false ) );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( $stock_quantity );
		$product->save();

		return $product;
	}

	public function test_low_stock_is_emitted(): void {
		$this->truncate_history();

		$product = $this->create_product( 2 );
		do_action( 'woocommerce_low_stock', $product );

		$rows = $this->rows_for_type( 'woocommerce.stock_threshold_crossed' );
		$this->assertCount( 1, $rows );

		$projected = json_decode( $rows[0]['projected_fields_json'], true );
		$this->assertSame( $product->get_id(), $projected['subject']['product_id'] );
		$this->assertSame( 'low', $projected['payload']['status'] );
		$this->assertSame( 2, $projected['payload']['stock_quantity'] );
		$this->assertSame( $product->get_sku(), $projected['payload']['product_sku'] );
	}

	public function test_no_stock_is_emitted(): void {
		$this->truncate_history();

		$product = $this->create_product( 0 );
		do_action( 'woocommerce_no_stock', $product );

		$rows = $this->rows_for_type( 'woocommerce.stock_threshold_crossed' );
		$this->assertCount( 1, $rows );

		$projected = json_decode( $rows[0]['projected_fields_json'], true );
		$this->assertSame( 'out', $projected['payload']['status'] );
	}

	public function test_dual_code_path_for_the_same_quantity_and_status_collapses_to_one_event(): void {
		$this->truncate_history();

		$product = $this->create_product( 1 );

		// Simulates wc_trigger_stock_change_notifications() and
		// wc_trigger_stock_change_actions() both firing woocommerce_low_stock
		// for the same (product_id, status, stock_quantity) occurrence.
		do_action( 'woocommerce_low_stock', $product );
		do_action( 'woocommerce_low_stock', $product );

		$rows = $this->rows_for_type( 'woocommerce.stock_threshold_crossed' );
		$this->assertCount( 1, $rows, 'Two firings for the same product/status/quantity must dedupe (state-keyed coalescing, M03 plan §5.2).' );
	}

	public function test_a_different_quantity_is_a_distinct_occurrence(): void {
		$this->truncate_history();

		$product = $this->create_product( 3 );
		do_action( 'woocommerce_low_stock', $product );

		$product->set_stock_quantity( 1 );
		$product->save();
		do_action( 'woocommerce_low_stock', $product );

		$rows = $this->rows_for_type( 'woocommerce.stock_threshold_crossed' );
		$this->assertCount( 2, $rows, 'Distinct stock quantities must not coalesce.' );
	}

	public function test_low_and_no_stock_for_the_same_product_are_distinct_by_status(): void {
		$this->truncate_history();

		$product = $this->create_product( 0 );
		do_action( 'woocommerce_low_stock', $product );
		do_action( 'woocommerce_no_stock', $product );

		$rows = $this->rows_for_type( 'woocommerce.stock_threshold_crossed' );
		$this->assertCount( 2, $rows );
	}
}
