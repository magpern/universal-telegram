<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Integrations\WooCommerce\Visitor;

use UniversalTelegram\Audit\AuditLogger;
use UniversalTelegram\Core\Configuration\Settings;
use UniversalTelegram\Core\Plugin;
use UniversalTelegram\Events\Visitor\IngestController;
use UniversalTelegram\Events\Visitor\IngestRequestValidator;
use UniversalTelegram\Events\Visitor\BotFilter;
use UniversalTelegram\Events\Visitor\Sampler;
use UniversalTelegram\Integrations\WooCommerce\Visitor\VisitorCommerceEventCatalog;
use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Privacy\Redactor;
use UniversalTelegram\Telegram\Reliability\RateLimiter;
use WP_REST_Request;
use WP_UnitTestCase;

final class VisitorCommerceEventCatalogTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();

		if ( ! getenv( 'UT_TEST_WC_ACTIVE' ) ) {
			$this->markTestSkipped( 'WooCommerce is not active in this configuration.' );
		}
	}

	public function test_the_three_commerce_types_are_registered_only_when_woocommerce_is_active(): void {
		$registry = Plugin::instance()->event_registry();

		$this->assertTrue( $registry->is_registered( VisitorCommerceEventCatalog::PRODUCT_VIEWED ) );
		$this->assertTrue( $registry->is_registered( VisitorCommerceEventCatalog::ADD_TO_CART_INTENT ) );
		$this->assertTrue( $registry->is_registered( VisitorCommerceEventCatalog::CHECKOUT_STARTED_INTENT ) );
	}

	public function test_add_to_cart_intent_is_distinct_from_the_m03_cart_item_added_event(): void {
		$registry = Plugin::instance()->event_registry();

		$this->assertNotSame(
			$registry->classification_map_for( VisitorCommerceEventCatalog::ADD_TO_CART_INTENT ),
			$registry->classification_map_for( 'woocommerce.cart_item_added' )
		);
		$this->assertTrue( $registry->is_registered( 'woocommerce.cart_item_added' ) );
	}

	public function test_a_forged_nonexistent_product_id_is_rejected_400(): void {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . Migrator::RATE_LIMIT_TABLE ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		update_option(
			Settings::OPTION_NAME,
			array_merge(
				( new Settings() )->defaults(),
				array(
					'visitor_tracking_enabled' => true,
					'visitor_family_commerce'  => true,
				)
			)
		);

		$schema_health = new SchemaHealth();
		$controller    = new IngestController(
			$schema_health,
			Plugin::instance()->event_registry(),
			new Settings(),
			new RateLimiter( $schema_health ),
			new IngestRequestValidator(),
			new BotFilter(),
			new Sampler(),
			new AuditLogger( $schema_health, new Redactor() )
		);

		$body = wp_json_encode(
			array(
				'v'      => 1,
				'visit'  => str_repeat( 'b', 32 ),
				'events' => array(
					array(
						'uuid' => '22222222-2222-4222-8222-222222222222',
						'type' => 'pd',
						'data' => array( 'product_id' => 999999999 ),
					),
				),
			)
		);

		$request = new WP_REST_Request( 'POST', '/universal-telegram/v1/visitor-events' );
		$request->set_header( 'Origin', home_url() );
		$request->set_header( 'User-Agent', 'Mozilla/5.0 (test browser)' );
		$request->set_body( $body );

		$response = $controller->handle_request( $request );

		$this->assertSame( 400, $response->get_status() );
	}
}
