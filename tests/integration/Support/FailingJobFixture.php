<?php
/**
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Tests\Integration\Support;

use RuntimeException;

/**
 * Development-only job handler fixture. Configurable to fail or succeed,
 * and counts its own invocations, so integration tests can assert exactly
 * how many times a job handler actually ran.
 */
final class FailingJobFixture {

	/**
	 * @var int
	 */
	public static int $invocation_count = 0;

	/**
	 * @var bool
	 */
	public static bool $should_throw = true;

	/**
	 * Resets static state between tests.
	 */
	public static function reset(): void {
		self::$invocation_count = 0;
		self::$should_throw     = true;
	}

	/**
	 * The handler itself.
	 *
	 * @param array<string, mixed> $job The job's full action-args array (unused).
	 *
	 * @throws RuntimeException When self::$should_throw is true.
	 */
	public function __invoke( array $job ): void {
		++self::$invocation_count;

		if ( self::$should_throw ) {
			throw new RuntimeException( 'Deliberate test failure.' );
		}
	}
}
