<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Automations;

use PHPUnit\Framework\TestCase;
use UniversalTelegram\Automations\ConditionOperator;

final class ConditionOperatorTest extends TestCase {

	public function test_equals(): void {
		$this->assertTrue( ConditionOperator::EQUALS->matches( 'failed', 'failed' ) );
		$this->assertTrue( ConditionOperator::EQUALS->matches( 1, '1' ) );
		$this->assertFalse( ConditionOperator::EQUALS->matches( 'failed', 'completed' ) );
	}

	public function test_not_equals(): void {
		$this->assertFalse( ConditionOperator::NOT_EQUALS->matches( 'failed', 'failed' ) );
		$this->assertTrue( ConditionOperator::NOT_EQUALS->matches( 'failed', 'completed' ) );
	}

	public function test_contains(): void {
		$this->assertTrue( ConditionOperator::CONTAINS->matches( 'hello world', 'world' ) );
		$this->assertFalse( ConditionOperator::CONTAINS->matches( 'hello world', 'xyz' ) );
		$this->assertFalse( ConditionOperator::CONTAINS->matches( 42, 'world' ) );
	}

	public function test_not_contains(): void {
		$this->assertTrue( ConditionOperator::NOT_CONTAINS->matches( 'hello world', 'xyz' ) );
		$this->assertFalse( ConditionOperator::NOT_CONTAINS->matches( 'hello world', 'world' ) );
		$this->assertFalse( ConditionOperator::NOT_CONTAINS->matches( 42, 'world' ) );
	}

	public function test_greater_than(): void {
		$this->assertTrue( ConditionOperator::GREATER_THAN->matches( 10, 5 ) );
		$this->assertFalse( ConditionOperator::GREATER_THAN->matches( 5, 10 ) );
		$this->assertFalse( ConditionOperator::GREATER_THAN->matches( 'x', 5 ) );
	}

	public function test_less_than(): void {
		$this->assertTrue( ConditionOperator::LESS_THAN->matches( 5, 10 ) );
		$this->assertFalse( ConditionOperator::LESS_THAN->matches( 10, 5 ) );
	}

	public function test_at_least(): void {
		$this->assertTrue( ConditionOperator::AT_LEAST->matches( 10, 10 ) );
		$this->assertTrue( ConditionOperator::AT_LEAST->matches( 11, 10 ) );
		$this->assertFalse( ConditionOperator::AT_LEAST->matches( 9, 10 ) );
		$this->assertFalse( ConditionOperator::AT_LEAST->matches( 'x', 10 ) );
	}

	public function test_at_most(): void {
		$this->assertTrue( ConditionOperator::AT_MOST->matches( 10, 10 ) );
		$this->assertTrue( ConditionOperator::AT_MOST->matches( 9, 10 ) );
		$this->assertFalse( ConditionOperator::AT_MOST->matches( 11, 10 ) );
		$this->assertFalse( ConditionOperator::AT_MOST->matches( 'x', 10 ) );
	}

	public function test_in(): void {
		$this->assertTrue( ConditionOperator::IN->matches( 'b', array( 'a', 'b', 'c' ) ) );
		$this->assertFalse( ConditionOperator::IN->matches( 'z', array( 'a', 'b', 'c' ) ) );
		$this->assertFalse( ConditionOperator::IN->matches( 'a', 'not-an-array' ) );
	}

	public function test_a_non_scalar_never_matches_equals(): void {
		$this->assertFalse( ConditionOperator::EQUALS->matches( array( 'x' ), array( 'x' ) ) );
	}
}
