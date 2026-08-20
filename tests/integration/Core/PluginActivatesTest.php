<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Core;

use WP_UnitTestCase;

final class PluginActivatesTest extends WP_UnitTestCase {

	public function test_plugin_loads_with_no_fatal_error(): void {
		$this->assertTrue( class_exists( \UniversalTelegram\Core\Plugin::class ) );
		$this->assertNotNull( \UniversalTelegram\Core\Plugin::instance() );
	}
}
