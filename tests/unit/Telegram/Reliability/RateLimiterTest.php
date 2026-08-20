<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Telegram\Reliability;

use PHPUnit\Framework\TestCase;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Telegram\Reliability\RateLimiter;

final class RateLimiterTest extends TestCase {

	/**
	 * @param array<string, array{tokens_available: float, last_refill_at: int}> $store The in-memory state double.
	 * @param int                                                                $now   The fixed clock value.
	 */
	private function limiter( array &$store, int $now ): RateLimiter {
		return new class( new SchemaHealth(), static function () use ( $now ) {
			return $now;
		}, $store ) extends RateLimiter {
			/**
			 * @var array<string, array{tokens_available: float, last_refill_at: int}>
			 */
			private array $store;

			public function __construct( SchemaHealth $schema_health, callable $clock, array &$store ) {
				parent::__construct( $schema_health, $clock );
				$this->store = &$store;
			}

			protected function read_state( string $scope_type, int $scope_id ): ?array {
				return $this->store[ $scope_type . ':' . $scope_id ] ?? null;
			}

			protected function write_state( string $scope_type, int $scope_id, float $tokens_available, int $last_refill_at ): void {
				$this->store[ $scope_type . ':' . $scope_id ] = array(
					'tokens_available' => $tokens_available,
					'last_refill_at'   => $last_refill_at,
				);
			}
		};
	}

	public function test_a_scope_with_no_prior_state_starts_with_a_full_bucket_and_consumes_one(): void {
		$store   = array();
		$limiter = $this->limiter( $store, 1000 );

		$this->assertTrue( $limiter->try_consume( 'destination', 1, 1.0, 1.0 ) );
		$this->assertSame( 0.0, $store['destination:1']['tokens_available'] );
	}

	public function test_an_empty_bucket_refuses_until_refilled_by_elapsed_time(): void {
		$store = array(
			'destination:1' => array(
				'tokens_available' => 0.0,
				'last_refill_at'   => 1000,
			),
		);

		// No time has passed: still empty.
		$limiter = $this->limiter( $store, 1000 );
		$this->assertFalse( $limiter->try_consume( 'destination', 1, 1.0, 1.0 ) );

		// One second later, at 1 token/sec, exactly one token is available.
		$limiter = $this->limiter( $store, 1001 );
		$this->assertTrue( $limiter->try_consume( 'destination', 1, 1.0, 1.0 ) );
	}

	public function test_refill_never_exceeds_capacity(): void {
		$store = array(
			'bot:1' => array(
				'tokens_available' => 0.0,
				'last_refill_at'   => 1000,
			),
		);

		// 1000 seconds elapsed at 20 tokens/sec would vastly exceed a
		// capacity of 20 — must be capped.
		$limiter = $this->limiter( $store, 2000 );
		$this->assertTrue( $limiter->try_consume( 'bot', 1, 20.0, 20.0 ) );

		$this->assertSame( 19.0, $store['bot:1']['tokens_available'] );
	}

	public function test_distinct_scopes_do_not_share_a_bucket(): void {
		$store   = array();
		$limiter = $this->limiter( $store, 1000 );

		// Exhaust bot 1's bucket of capacity 1.
		$this->assertTrue( $limiter->try_consume( 'bot', 1, 1.0, 1.0 ) );
		$this->assertFalse( $limiter->try_consume( 'bot', 1, 1.0, 1.0 ) );

		// An unrelated bot is unaffected.
		$this->assertTrue( $limiter->try_consume( 'bot', 2, 1.0, 1.0 ) );
	}
}
