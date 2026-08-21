<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Integrations\WooCommerce\Events;

use UniversalTelegram\Persistence\Migrator;
use WP_Error;
use WP_UnitTestCase;

final class CheckoutEventEmitterTest extends WP_UnitTestCase {

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

	public function test_classic_validation_failure_is_emitted_without_mutating_errors(): void {
		$this->truncate_history();

		$errors = new WP_Error();
		$errors->add( 'billing_email_invalid', 'Please provide a valid billing email.' );

		do_action( 'woocommerce_after_checkout_validation', array(), $errors );

		// The callback must not have mutated the shared WP_Error object.
		$this->assertSame( array( 'billing_email_invalid' ), $errors->get_error_codes() );

		$rows = $this->rows_for_type( 'woocommerce.checkout_validation_failed' );
		$this->assertCount( 1, $rows );

		$projected = json_decode( $rows[0]['projected_fields_json'], true );
		$this->assertSame( 'billing_email_invalid', $projected['payload']['error_codes_csv'] );
		$this->assertSame( 'classic', $projected['context']['checkout_type'] );
	}

	public function test_no_error_codes_emits_nothing(): void {
		$this->truncate_history();

		$errors = new WP_Error();
		do_action( 'woocommerce_after_checkout_validation', array(), $errors );

		$this->assertCount( 0, $this->rows_for_type( 'woocommerce.checkout_validation_failed' ) );
	}

	public function test_error_message_text_never_appears_only_stable_error_codes(): void {
		$this->truncate_history();

		$errors = new WP_Error();
		$errors->add( 'billing_phone_invalid', 'A free-text message that could echo user input.' );

		do_action( 'woocommerce_after_checkout_validation', array(), $errors );

		$rows = $this->rows_for_type( 'woocommerce.checkout_validation_failed' );
		$this->assertStringNotContainsString( 'free-text message', $rows[0]['projected_fields_json'] );
	}

	public function test_no_block_checkout_binding_exists_for_this_emitter(): void {
		// Documented gap (M03 plan §5.10, ADR-0018 Decision §8): no
		// Store API/block-checkout equivalent hook exists in WooCommerce
		// core, so this emitter registers exactly one WordPress hook
		// callback, on the classic-only woocommerce_after_checkout_validation
		// action.
		$reflection = new \ReflectionClass( \UniversalTelegram\Integrations\WooCommerce\Events\CheckoutEventEmitter::class );
		$methods    = array_map(
			static function ( \ReflectionMethod $method ) {
				return $method->getName();
			},
			$reflection->getMethods( \ReflectionMethod::IS_PUBLIC )
		);

		$this->assertContains( 'on_checkout_validation', $methods );
		$this->assertCount( 3, $methods, 'Exactly register_event_types(), register_hooks(), and on_checkout_validation() are expected — no additional block-checkout callback.' );
	}
}
