<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Core;

use PHPUnit\Framework\TestCase;
use UniversalTelegram\Core\Plugin;

final class PluginTest extends TestCase {

	public function test_instance_returns_the_same_object_across_calls(): void {
		$this->assertSame( Plugin::instance(), Plugin::instance() );
	}

	public function test_init_is_idempotent(): void {
		$plugin = Plugin::instance();

		// Calling init() twice must not throw and must not change identity.
		$plugin->init();
		$plugin->init();

		$this->assertSame( $plugin, Plugin::instance() );
	}
}
