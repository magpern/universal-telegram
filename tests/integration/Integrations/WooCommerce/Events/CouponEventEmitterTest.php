<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Integrations\WooCommerce\Events;

use UniversalTelegram\Persistence\Migrator;
use WP_UnitTestCase;

final class CouponEventEmitterTest extends WP_UnitTestCase {

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

	private function create_coupon( string $code ): \WC_Coupon {
		$coupon = new \WC_Coupon();
		$coupon->set_code( $code );
		$coupon->set_discount_type( 'percent' );
		$coupon->set_amount( 10 );
		$coupon->save();

		return $coupon;
	}

	public function test_coupon_applied_is_emitted(): void {
		$this->truncate_history();

		do_action( 'woocommerce_applied_coupon', 'save10' );

		$rows = $this->rows_for_type( 'woocommerce.coupon_applied' );
		$this->assertCount( 1, $rows );

		$projected = json_decode( $rows[0]['projected_fields_json'], true );
		$this->assertSame( 'save10', $projected['subject']['coupon_code'] );
	}

	public function test_coupon_applied_repeated_within_the_same_bucket_coalesces(): void {
		$this->truncate_history();

		do_action( 'woocommerce_applied_coupon', 'save10' );
		do_action( 'woocommerce_applied_coupon', 'save10' );

		$rows = $this->rows_for_type( 'woocommerce.coupon_applied' );
		$this->assertCount( 1, $rows, 'Rapid double-submit within the same 5-second bucket must coalesce (M03 plan §5.2).' );
	}

	public function test_coupon_rejected_is_emitted_via_the_filter_and_returns_the_message_unmodified(): void {
		$this->truncate_history();

		$coupon = $this->create_coupon( 'invalidme' );

		$result = apply_filters( 'woocommerce_coupon_error', 'Coupon usage limit has been reached.', 106, $coupon );

		$this->assertSame( 'Coupon usage limit has been reached.', $result, 'The filter callback must return $message unmodified.' );

		$rows = $this->rows_for_type( 'woocommerce.coupon_rejected' );
		$this->assertCount( 1, $rows );

		$projected = json_decode( $rows[0]['projected_fields_json'], true );
		$this->assertSame( 'invalidme', $projected['subject']['coupon_code'] );
		$this->assertSame( 106, $projected['payload']['error_code'] );
		$this->assertStringNotContainsString( 'Coupon usage limit has been reached', $rows[0]['projected_fields_json'] );
	}

	public function test_coupon_rejected_never_records_the_error_message_text(): void {
		$this->truncate_history();

		apply_filters( 'woocommerce_coupon_error', 'A dynamically generated, potentially sensitive message.', 100, 'unknown-code' );

		$rows = $this->rows_for_type( 'woocommerce.coupon_rejected' );
		$this->assertCount( 1, $rows );
		$this->assertStringNotContainsString( 'dynamically generated', $rows[0]['projected_fields_json'] );

		$projected = json_decode( $rows[0]['projected_fields_json'], true );
		$this->assertSame( 'unknown-code', $projected['subject']['coupon_code'] );
	}
}
