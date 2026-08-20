<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Queue;

use PHPUnit\Framework\TestCase;
use UniversalTelegram\Queue\RetryPolicy;

final class RetryPolicyTest extends TestCase {

	public function test_max_attempts_is_five(): void {
		$this->assertSame( 5, ( new RetryPolicy() )->max_attempts() );
	}

	public function test_should_retry_is_true_below_the_ceiling_and_false_at_it(): void {
		$policy = new RetryPolicy();

		$this->assertTrue( $policy->should_retry( 4 ) );
		$this->assertFalse( $policy->should_retry( 5 ) );
	}

	public function test_delay_seconds_is_exact_with_zero_jitter(): void {
		$policy = new RetryPolicy(
			null,
			static function ( int $max ): int {
				return 0;
			}
		);

		$this->assertSame( 30, $policy->delay_seconds( 1 ) );
		$this->assertSame( 60, $policy->delay_seconds( 2 ) );
		$this->assertSame( 120, $policy->delay_seconds( 3 ) );
		$this->assertSame( 240, $policy->delay_seconds( 4 ) );
		$this->assertSame( 480, $policy->delay_seconds( 5 ) );
	}

	public function test_delay_seconds_is_capped_at_nine_hundred(): void {
		$policy = new RetryPolicy(
			null,
			static function ( int $max ): int {
				return 0;
			}
		);

		$this->assertSame( 900, $policy->delay_seconds( 10 ) );
	}

	public function test_jitter_is_added_on_top_of_the_capped_delay(): void {
		$policy = new RetryPolicy(
			null,
			static function ( int $max ): int {
				return $max;
			}
		);

		// attempt 1: capped delay 30, max jitter floor(30 * 0.2) = 6.
		$this->assertSame( 36, $policy->delay_seconds( 1 ) );
	}

	public function test_next_attempt_timestamp_uses_the_injected_clock(): void {
		$policy = new RetryPolicy(
			static function (): int {
				return 1000;
			},
			static function ( int $max ): int {
				return 0;
			}
		);

		$this->assertSame( 1030, $policy->next_attempt_timestamp( 1 ) );
	}
}
