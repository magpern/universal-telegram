<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Privacy;

use PHPUnit\Framework\TestCase;
use UniversalTelegram\Privacy\Classification;

final class ClassificationTest extends TestCase {

	public function test_exactly_four_levels_exist(): void {
		$this->assertCount( 4, Classification::cases() );
	}

	public function test_each_level_has_a_stable_string_value(): void {
		$this->assertSame( 'public', Classification::PUBLIC->value );
		$this->assertSame( 'internal', Classification::INTERNAL->value );
		$this->assertSame( 'sensitive', Classification::SENSITIVE->value );
		$this->assertSame( 'secret', Classification::SECRET->value );
	}
}
