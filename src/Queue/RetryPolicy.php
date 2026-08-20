<?php
/**
 * Generic retry policy.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Queue;

/**
 * Entirely generic: a bounded maximum number of attempts, exponential
 * backoff with jitter, no awareness of any specific provider's own
 * failure semantics. Clock and jitter are injectable seams so exact
 * expected delays can be asserted in tests, not merely checked to fall
 * within a range.
 */
final class RetryPolicy {

	private const MAX_ATTEMPTS       = 5;
	private const BASE_DELAY_SECONDS = 30;
	private const MAX_DELAY_SECONDS  = 900;

	/**
	 * Returns the current time.
	 *
	 * @var callable(): int
	 */
	private $clock;

	/**
	 * Given a maximum, returns a random jitter amount.
	 *
	 * @var callable(int): int
	 */
	private $jitter;

	/**
	 * Constructor.
	 *
	 * @param callable(): int|null    $clock  Returns the current time. Defaults to time().
	 * @param callable(int): int|null $jitter Given a maximum, returns a random jitter amount.
	 *                                         Defaults to random_int(0, $max).
	 */
	public function __construct( ?callable $clock = null, ?callable $jitter = null ) {
		$this->clock  = $clock ?? static function (): int {
			return time();
		};
		$this->jitter = $jitter ?? static function ( int $max ): int {
			return $max > 0 ? random_int( 0, $max ) : 0;
		};
	}

	/**
	 * The maximum number of attempts permitted.
	 *
	 * @return int
	 */
	public function max_attempts(): int {
		return self::MAX_ATTEMPTS;
	}

	/**
	 * Whether another attempt is permitted after the given attempt number.
	 *
	 * @param int $attempt The attempt number that just ran.
	 *
	 * @return bool
	 */
	public function should_retry( int $attempt ): bool {
		return $attempt < self::MAX_ATTEMPTS;
	}

	/**
	 * The backoff delay, in seconds, before the next attempt.
	 *
	 * @param int $attempt The attempt number that just ran.
	 *
	 * @return int
	 */
	public function delay_seconds( int $attempt ): int {
		$exponential = self::BASE_DELAY_SECONDS * ( 2 ** max( 0, $attempt - 1 ) );
		$capped      = min( $exponential, self::MAX_DELAY_SECONDS );

		return $capped + ( $this->jitter )( (int) floor( $capped * 0.2 ) );
	}

	/**
	 * The absolute timestamp the next attempt should run at.
	 *
	 * @param int $attempt The attempt number that just ran.
	 *
	 * @return int
	 */
	public function next_attempt_timestamp( int $attempt ): int {
		return ( $this->clock )() + $this->delay_seconds( $attempt );
	}
}
