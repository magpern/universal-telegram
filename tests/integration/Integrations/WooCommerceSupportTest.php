<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Integrations;

use UniversalTelegram\Integrations\WooCommerce\WooCommerceSupport;
use WP_UnitTestCase;

final class WooCommerceSupportTest extends WP_UnitTestCase {

	public function test_reports_whether_woocommerce_is_active_in_this_configuration(): void {
		$support         = new WooCommerceSupport();
		$expected_active = (bool) getenv( 'UT_TEST_WC_ACTIVE' );

		$this->assertSame( $expected_active, $support->is_active() );
	}
}
