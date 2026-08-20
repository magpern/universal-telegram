<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Telegram\Reliability;

use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Queue\RetryPolicy;
use UniversalTelegram\Telegram\Reliability\CircuitBreaker;
use UniversalTelegram\Telegram\Reliability\CircuitBreakerState;
use UniversalTelegram\Telegram\Reliability\CircuitOpenException;
use WP_UnitTestCase;

final class CircuitBreakerTest extends WP_UnitTestCase {

	private function breaker( int $now ): CircuitBreaker {
		return new CircuitBreaker(
			new SchemaHealth(),
			new RetryPolicy(
				static function () use ( $now ) {
					return $now;
				},
				static function () {
					return 0;
				}
			),
			static function () use ( $now ) {
				return $now;
			}
		);
	}

	public function test_a_fresh_scope_is_closed_and_permits_attempts(): void {
		$breaker = $this->breaker( 1000 );

		$this->assertSame( CircuitBreakerState::CLOSED, $breaker->state( 'bot', 1 ) );
		$this->assertTrue( $breaker->may_attempt( 'bot', 1 ) );
	}

	public function test_bot_scope_opens_after_five_consecutive_failures(): void {
		$breaker = $this->breaker( 1000 );

		for ( $i = 0; $i < 4; $i++ ) {
			$breaker->record_failure( 'bot', 1, 5, 600 );
			$this->assertSame( CircuitBreakerState::CLOSED, $breaker->state( 'bot', 1 ) );
		}

		$breaker->record_failure( 'bot', 1, 5, 600 );
		$this->assertSame( CircuitBreakerState::OPEN, $breaker->state( 'bot', 1 ) );
		$this->assertFalse( $breaker->may_attempt( 'bot', 1 ) );
	}

	public function test_destination_scope_opens_after_three_consecutive_failures(): void {
		$breaker = $this->breaker( 1000 );

		$breaker->record_failure( 'destination', 1, 3, 600 );
		$breaker->record_failure( 'destination', 1, 3, 600 );
		$this->assertSame( CircuitBreakerState::CLOSED, $breaker->state( 'destination', 1 ) );

		$breaker->record_failure( 'destination', 1, 3, 600 );
		$this->assertSame( CircuitBreakerState::OPEN, $breaker->state( 'destination', 1 ) );
	}

	public function test_half_open_probe_is_granted_exactly_once_after_cooldown(): void {
		$breaker = $this->breaker( 1000 );

		for ( $i = 0; $i < 5; $i++ ) {
			$breaker->record_failure( 'bot', 1, 5, 600 );
		}
		$this->assertSame( CircuitBreakerState::OPEN, $breaker->state( 'bot', 1 ) );

		// Before the 60-second cooldown elapses, still refused.
		$too_soon = $this->breaker( 1030 );
		$this->assertFalse( $too_soon->may_attempt( 'bot', 1 ) );

		// After it elapses, exactly one trial is granted.
		$after_cooldown = $this->breaker( 1061 );
		$this->assertTrue( $after_cooldown->may_attempt( 'bot', 1 ) );
		$this->assertSame( CircuitBreakerState::HALF_OPEN, $after_cooldown->state( 'bot', 1 ) );

		// A second check while still half_open grants nothing further.
		$this->assertFalse( $after_cooldown->may_attempt( 'bot', 1 ) );
	}

	public function test_a_successful_probe_closes_the_breaker(): void {
		$breaker = $this->breaker( 1000 );

		for ( $i = 0; $i < 5; $i++ ) {
			$breaker->record_failure( 'bot', 1, 5, 600 );
		}

		$after_cooldown = $this->breaker( 1061 );
		$this->assertTrue( $after_cooldown->may_attempt( 'bot', 1 ) );

		$after_cooldown->record_success( 'bot', 1 );

		$this->assertSame( CircuitBreakerState::CLOSED, $after_cooldown->state( 'bot', 1 ) );
		$this->assertTrue( $after_cooldown->may_attempt( 'bot', 1 ) );
	}

	public function test_a_failed_probe_reopens_with_an_escalated_cooldown(): void {
		$breaker = $this->breaker( 1000 );

		for ( $i = 0; $i < 5; $i++ ) {
			$breaker->record_failure( 'bot', 1, 5, 600 );
		}

		$after_cooldown = $this->breaker( 1061 );
		$this->assertTrue( $after_cooldown->may_attempt( 'bot', 1 ) );

		$after_cooldown->record_failure( 'bot', 1, 5, 600 );
		$this->assertSame( CircuitBreakerState::OPEN, $after_cooldown->state( 'bot', 1 ) );

		// Escalated cooldown: RetryPolicy::delay_seconds(1) = 30s (with zero
		// jitter, injected above), longer than the fixed 60s first cooldown
		// would already have covered from 1061, so probing immediately at
		// 1062 is still refused.
		$still_too_soon = $this->breaker( 1062 );
		$this->assertFalse( $still_too_soon->may_attempt( 'bot', 1 ) );
	}

	public function test_401_opens_indefinitely_with_no_scheduled_probe(): void {
		$breaker = $this->breaker( 1000 );

		$breaker->open_indefinitely( 'bot', 1 );

		$this->assertSame( CircuitBreakerState::OPEN, $breaker->state( 'bot', 1 ) );

		$far_future = $this->breaker( 1000 + ( 365 * DAY_IN_SECONDS ) );
		$this->assertFalse( $far_future->may_attempt( 'bot', 1 ) );
	}

	public function test_assert_may_attempt_throws_with_the_next_probe_at(): void {
		$breaker = $this->breaker( 1000 );

		for ( $i = 0; $i < 5; $i++ ) {
			$breaker->record_failure( 'bot', 1, 5, 600 );
		}

		try {
			$breaker->assert_may_attempt( 'bot', 1 );
			$this->fail( 'Expected a CircuitOpenException.' );
		} catch ( CircuitOpenException $exception ) {
			$this->assertSame( 1060, $exception->next_probe_at() );
		}
	}

	public function test_destination_scope_is_independent_of_bot_scope(): void {
		$breaker = $this->breaker( 1000 );

		for ( $i = 0; $i < 5; $i++ ) {
			$breaker->record_failure( 'bot', 1, 5, 600 );
		}

		$this->assertSame( CircuitBreakerState::OPEN, $breaker->state( 'bot', 1 ) );
		$this->assertSame( CircuitBreakerState::CLOSED, $breaker->state( 'destination', 1 ) );
		$this->assertTrue( $breaker->may_attempt( 'destination', 1 ) );
	}
}
