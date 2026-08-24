<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Integrations\WooCommerce\Events;

use UniversalTelegram\Core\Plugin;
use UniversalTelegram\Persistence\Migrator;
use WP_UnitTestCase;

final class CartEventEmitterTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();

		if ( ! getenv( 'UT_TEST_WC_ACTIVE' ) ) {
			$this->markTestSkipped( 'WooCommerce is not active in this configuration.' );
		}

		WC()->session->init();
		WC()->cart->empty_cart();
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

	private function create_product(): \WC_Product_Simple {
		$product = new \WC_Product_Simple();
		$product->set_name( 'M03 cart test product' );
		$product->set_regular_price( '15.00' );
		$product->set_price( '15.00' );
		$product->save();

		return $product;
	}

	public function test_cart_item_added_is_emitted_with_rule_condition_usable_cart_total(): void {
		$this->truncate_history();

		$product = $this->create_product();
		WC()->cart->add_to_cart( $product->get_id(), 1 );

		$rows = $this->rows_for_type( 'woocommerce.cart_item_added' );
		$this->assertCount( 1, $rows );

		$projected = json_decode( $rows[0]['projected_fields_json'], true );
		$this->assertSame( $product->get_id(), $projected['subject']['product_id'] );
		$this->assertSame( 'M03 cart test product', $projected['payload']['product_name'] );
		$this->assertSame( 1, $projected['payload']['quantity'] );
		$this->assertArrayHasKey( 'cart_total', $projected['payload'] );

		$registry = Plugin::instance()->event_registry();
		$this->assertContains( 'payload.cart_total', $registry->allowed_variable_fields_for( 'woocommerce.cart_item_added' ) );
		$this->assertContains( 'payload.product_name', $registry->allowed_variable_fields_for( 'woocommerce.cart_item_added' ) );
	}

	public function test_repeated_additions_to_the_same_cart_line_coalesce_to_the_first_emission(): void {
		$this->truncate_history();

		$product = $this->create_product();
		WC()->cart->add_to_cart( $product->get_id(), 1 );
		WC()->cart->add_to_cart( $product->get_id(), 3 );

		$rows = $this->rows_for_type( 'woocommerce.cart_item_added' );
		$this->assertCount( 1, $rows, 'Line-identity coalescing must dedupe subsequent additions to the same cart line.' );

		$projected = json_decode( $rows[0]['projected_fields_json'], true );
		$this->assertSame( 1, $projected['payload']['quantity'], 'Recorded fields must reflect the first emission for that line, not the latest.' );
	}

	public function test_different_products_are_distinct_cart_lines(): void {
		$this->truncate_history();

		$product_one = $this->create_product();
		$product_two = $this->create_product();

		WC()->cart->add_to_cart( $product_one->get_id(), 1 );
		WC()->cart->add_to_cart( $product_two->get_id(), 1 );

		$rows = $this->rows_for_type( 'woocommerce.cart_item_added' );
		$this->assertCount( 2, $rows );
	}
}
