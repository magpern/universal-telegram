<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Core;

use UniversalTelegram\Core\Plugin;
use WP_UnitTestCase;

final class PluginActivatesTest extends WP_UnitTestCase {

	public function test_plugin_loads_with_no_fatal_error(): void {
		$this->assertTrue( class_exists( Plugin::class ) );
		$this->assertNotNull( Plugin::instance() );
	}

	public function test_init_is_idempotent_and_never_re_registers_a_hook(): void {
		$plugin = Plugin::instance();
		$plugin->init();

		$callback_count_before = $this->count_admin_init_callbacks();

		// A second call must be a pure no-op: no new hook registration.
		$plugin->init();
		$plugin->init();

		$callback_count_after = $this->count_admin_init_callbacks();

		$this->assertSame( $callback_count_before, $callback_count_after );
	}

	private function count_admin_init_callbacks(): int {
		global $wp_filter;

		if ( ! isset( $wp_filter['admin_init'] ) ) {
			return 0;
		}

		$count = 0;
		foreach ( $wp_filter['admin_init']->callbacks as $callbacks ) {
			$count += count( $callbacks );
		}

		return $count;
	}
}
