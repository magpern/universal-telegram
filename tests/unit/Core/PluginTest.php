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
}
