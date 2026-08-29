<?php
/**
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Tests\Unit\Queue;

use PHPUnit\Framework\TestCase;
use UniversalTelegram\Queue\DeliveryClass;

/**
 * @covers \UniversalTelegram\Queue\DeliveryClass
 */
final class DeliveryClassTest extends TestCase {

	public function test_vocabulary_is_exactly_two_fixed_values(): void {
		$this->assertSame( 'standard', DeliveryClass::STANDARD );
		$this->assertSame( 'interactive_chat', DeliveryClass::INTERACTIVE_CHAT );

		$this->assertTrue( DeliveryClass::is_valid( 'standard' ) );
		$this->assertTrue( DeliveryClass::is_valid( 'interactive_chat' ) );
		$this->assertFalse( DeliveryClass::is_valid( 'urgent' ) );
		$this->assertFalse( DeliveryClass::is_valid( '' ) );
		$this->assertFalse( DeliveryClass::is_valid( 'INTERACTIVE_CHAT' ) );
	}

	public function test_from_wire_absent_is_standard(): void {
		$this->assertSame( 'standard', DeliveryClass::from_wire( null ) );
	}

	public function test_from_wire_passes_valid_values(): void {
		$this->assertSame( 'standard', DeliveryClass::from_wire( 'standard' ) );
		$this->assertSame( 'interactive_chat', DeliveryClass::from_wire( 'interactive_chat' ) );
	}

	/**
	 * @dataProvider invalid_wire_values
	 *
	 * @param mixed $value An unacceptable present value.
	 */
	public function test_from_wire_returns_null_for_anything_else( $value ): void {
		$this->assertNull( DeliveryClass::from_wire( $value ), 'a present, non-vocabulary value must be rejected, never coerced' );
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function invalid_wire_values(): array {
		return array(
			'empty string'  => array( '' ),
			'unknown token' => array( 'priority' ),
			'wrong case'    => array( 'Interactive_Chat' ),
			'integer'       => array( 1 ),
			'bool'          => array( true ),
			'array'         => array( array( 'interactive_chat' ) ),
		);
	}

	public function test_from_storage_defends_against_a_poisoned_row(): void {
		$this->assertSame( 'interactive_chat', DeliveryClass::from_storage( 'interactive_chat' ) );
		$this->assertSame( 'standard', DeliveryClass::from_storage( 'standard' ) );
		$this->assertSame( 'standard', DeliveryClass::from_storage( 'garbage' ) );
		$this->assertSame( 'standard', DeliveryClass::from_storage( null ) );
		$this->assertSame( 'standard', DeliveryClass::from_storage( 42 ) );
	}
}
