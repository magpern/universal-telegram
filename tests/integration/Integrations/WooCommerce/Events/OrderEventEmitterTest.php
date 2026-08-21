<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Integrations\WooCommerce\Events;

use UniversalTelegram\Persistence\Migrator;
use WP_UnitTestCase;

final class OrderEventEmitterTest extends WP_UnitTestCase {

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

	private function create_order(): \WC_Order {
		$order = wc_create_order();
		$order->set_currency( 'USD' );
		$order->add_product( $this->create_product(), 2 );
		$order->calculate_totals();
		$order->save();

		return $order;
	}

	private function create_product(): \WC_Product_Simple {
		$product = new \WC_Product_Simple();
		$product->set_name( 'M03 test product' );
		$product->set_regular_price( '25.00' );
		$product->set_price( '25.00' );
		$product->save();

		return $product;
	}

	public function test_order_created_is_emitted_on_classic_checkout_hook(): void {
		$this->truncate_history();

		$order = $this->create_order();
		do_action( 'woocommerce_checkout_order_processed', $order->get_id(), array(), $order );

		$rows = $this->rows_for_type( 'woocommerce.order_created' );
		$this->assertCount( 1, $rows );

		$projected = json_decode( $rows[0]['projected_fields_json'], true );
		$this->assertSame( $order->get_id(), $projected['subject']['order_id'] );
		$this->assertSame( (float) $order->get_total(), $projected['payload']['order_total'] );
		$this->assertSame( 'USD', $projected['payload']['currency'] );
		$this->assertSame( 2, $projected['payload']['item_count'] );
	}

	public function test_order_created_is_emitted_on_block_checkout_hook(): void {
		$this->truncate_history();

		$order = $this->create_order();
		do_action( 'woocommerce_store_api_checkout_order_processed', $order );

		$rows = $this->rows_for_type( 'woocommerce.order_created' );
		$this->assertCount( 1, $rows );
	}

	public function test_classic_and_block_hooks_for_the_same_order_collapse_to_one_event_id(): void {
		$this->truncate_history();

		$order = $this->create_order();
		do_action( 'woocommerce_checkout_order_processed', $order->get_id(), array(), $order );
		do_action( 'woocommerce_store_api_checkout_order_processed', $order );

		$rows = $this->rows_for_type( 'woocommerce.order_created' );
		$this->assertCount( 1, $rows, 'Classic and block hooks for the same order must dedupe to a single event.' );
	}

	public function test_order_created_never_contains_payment_method_or_pii_fields(): void {
		$this->truncate_history();

		$order = $this->create_order();
		do_action( 'woocommerce_checkout_order_processed', $order->get_id(), array(), $order );

		$rows = $this->rows_for_type( 'woocommerce.order_created' );
		$json = $rows[0]['projected_fields_json'];

		foreach ( array( 'payment_method', 'email', 'billing_', 'shipping_', 'phone', 'address', 'transaction_id' ) as $forbidden ) {
			$this->assertStringNotContainsString( $forbidden, $json );
		}
	}

	public function test_order_status_changed_is_emitted(): void {
		$this->truncate_history();

		$order = $this->create_order();
		$order->set_status( 'pending' );
		$order->save();

		do_action( 'woocommerce_order_status_changed', $order->get_id(), 'pending', 'processing', $order );

		$rows = $this->rows_for_type( 'woocommerce.order_status_changed' );
		$this->assertCount( 1, $rows );

		$projected = json_decode( $rows[0]['projected_fields_json'], true );
		$this->assertSame( 'pending', $projected['payload']['status_from'] );
		$this->assertSame( 'processing', $projected['payload']['status_to'] );
	}

	public function test_order_status_changed_idempotency_key_coalesces_within_the_same_second(): void {
		$this->truncate_history();

		$order = $this->create_order();

		do_action( 'woocommerce_order_status_changed', $order->get_id(), 'pending', 'processing', $order );
		do_action( 'woocommerce_order_status_changed', $order->get_id(), 'pending', 'processing', $order );

		$rows = $this->rows_for_type( 'woocommerce.order_status_changed' );
		$this->assertCount( 1, $rows, 'Two firings for the same status pair within the same wall-clock second must collapse to one event (attempt-window coalescing, M03 plan §5.2).' );
	}

	public function test_order_status_changed_distinct_status_pairs_are_independent(): void {
		$this->truncate_history();

		$order = $this->create_order();

		do_action( 'woocommerce_order_status_changed', $order->get_id(), 'pending', 'processing', $order );
		do_action( 'woocommerce_order_status_changed', $order->get_id(), 'processing', 'completed', $order );

		$rows = $this->rows_for_type( 'woocommerce.order_status_changed' );
		$this->assertCount( 2, $rows, 'Distinct status pairs must not coalesce.' );
	}

	public function test_order_fields_match_hpos_storage_agnostic_getters(): void {
		$this->truncate_history();

		$order = $this->create_order();
		do_action( 'woocommerce_checkout_order_processed', $order->get_id(), array(), $order );

		$reloaded = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $reloaded );

		$rows      = $this->rows_for_type( 'woocommerce.order_created' );
		$projected = json_decode( $rows[0]['projected_fields_json'], true );

		$this->assertSame( $reloaded->get_id(), $projected['subject']['order_id'] );
		$this->assertSame( (float) $reloaded->get_total(), $projected['payload']['order_total'] );
		$this->assertSame( $reloaded->get_status(), $projected['context']['order_status'] );
	}

	public function test_payment_completed_is_emitted_with_has_transaction_id_boolean_only(): void {
		$this->truncate_history();

		$order = $this->create_order();
		do_action( 'woocommerce_payment_complete', $order->get_id(), 'txn-12345' );

		$rows = $this->rows_for_type( 'woocommerce.payment_completed' );
		$this->assertCount( 1, $rows );

		$projected = json_decode( $rows[0]['projected_fields_json'], true );
		$this->assertTrue( $projected['context']['has_transaction_id'] );
		$this->assertArrayNotHasKey( 'transaction_id', $projected['payload'] ?? array() );
		$this->assertStringNotContainsString( 'txn-12345', $rows[0]['projected_fields_json'] );
	}

	public function test_payment_completed_without_a_transaction_id_reports_false(): void {
		$this->truncate_history();

		$order = $this->create_order();
		do_action( 'woocommerce_payment_complete', $order->get_id(), '' );

		$rows      = $this->rows_for_type( 'woocommerce.payment_completed' );
		$projected = json_decode( $rows[0]['projected_fields_json'], true );
		$this->assertFalse( $projected['context']['has_transaction_id'] );
	}

	public function test_order_failed_is_emitted_and_named_as_status_transition_not_payment_failure(): void {
		$this->truncate_history();

		$order = $this->create_order();
		do_action( 'woocommerce_order_status_failed', $order->get_id(), $order, array( 'from' => 'pending' ) );

		$rows = $this->rows_for_type( 'woocommerce.order_failed' );
		$this->assertCount( 1, $rows );

		$projected = json_decode( $rows[0]['projected_fields_json'], true );
		$this->assertSame( 'pending', $projected['payload']['status_from'] );
	}

	public function test_order_failed_and_order_status_changed_both_fire_for_one_transition_with_distinct_event_ids(): void {
		$this->truncate_history();

		$order = $this->create_order();
		do_action( 'woocommerce_order_status_changed', $order->get_id(), 'pending', 'failed', $order );
		do_action( 'woocommerce_order_status_failed', $order->get_id(), $order, array( 'from' => 'pending' ) );

		$changed_rows = $this->rows_for_type( 'woocommerce.order_status_changed' );
		$failed_rows  = $this->rows_for_type( 'woocommerce.order_failed' );

		$this->assertCount( 1, $changed_rows );
		$this->assertCount( 1, $failed_rows );
		$this->assertNotSame( $changed_rows[0]['event_id'], $failed_rows[0]['event_id'] );
	}

	public function test_order_cancelled_is_emitted(): void {
		$this->truncate_history();

		$order = $this->create_order();
		do_action( 'woocommerce_order_status_cancelled', $order->get_id(), $order, array( 'from' => 'processing' ) );

		$rows = $this->rows_for_type( 'woocommerce.order_cancelled' );
		$this->assertCount( 1, $rows );

		$projected = json_decode( $rows[0]['projected_fields_json'], true );
		$this->assertSame( 'processing', $projected['payload']['status_from'] );
	}

	public function test_refund_created_is_emitted_without_reason_text(): void {
		$this->truncate_history();

		$order = $this->create_order();
		$order->set_status( 'processing' );
		$order->save();

		$refund = wc_create_refund(
			array(
				'order_id'   => $order->get_id(),
				'amount'     => '10.00',
				'reason'     => 'a customer complaint containing incidental free text',
				'line_items' => array(),
			)
		);
		$this->assertNotInstanceOf( \WP_Error::class, $refund );

		$rows = $this->rows_for_type( 'woocommerce.refund_created' );
		$this->assertCount( 1, $rows );

		$projected = json_decode( $rows[0]['projected_fields_json'], true );
		$this->assertSame( $order->get_id(), $projected['subject']['order_id'] );
		$this->assertSame( $refund->get_id(), $projected['subject']['refund_id'] );
		$this->assertEqualsWithDelta( 10.00, $projected['payload']['refund_amount'], 0.001 );
		$this->assertStringNotContainsString( 'customer complaint', $rows[0]['projected_fields_json'] );
	}

	public function test_refund_created_idempotency_key_is_the_refund_id_alone(): void {
		$this->truncate_history();

		$order = $this->create_order();
		$order->set_status( 'processing' );
		$order->save();

		$refund = wc_create_refund(
			array(
				'order_id'   => $order->get_id(),
				'amount'     => '5.00',
				'line_items' => array(),
			)
		);
		$this->assertNotInstanceOf( \WP_Error::class, $refund );

		do_action( 'woocommerce_order_refunded', $order->get_id(), $refund->get_id() );
		do_action( 'woocommerce_order_refunded', $order->get_id(), $refund->get_id() );

		$rows = $this->rows_for_type( 'woocommerce.refund_created' );
		$this->assertCount( 1, $rows, 'wc_create_refund() itself already fires the hook once; a second manual firing for the same refund id must dedupe.' );
	}
}
