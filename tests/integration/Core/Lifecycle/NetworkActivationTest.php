<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Core\Lifecycle;

use UniversalTelegram\Core\Lifecycle\Activator;
use WP_UnitTestCase;

final class NetworkActivationTest extends WP_UnitTestCase {

	public function test_network_wide_activation_is_refused(): void {
		$activator = new Activator();

		$this->expectException( \WPDieException::class );

		$activator->activate( true );
	}

	public function test_single_site_activation_completes_normally(): void {
		$activator = new Activator();

		$activator->activate( false );

		// No exception, no wp_die(): reaching this line is the assertion.
		$this->assertTrue( true );
	}
}
