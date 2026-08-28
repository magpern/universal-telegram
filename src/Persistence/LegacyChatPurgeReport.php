<?php
/**
 * Result of a {@see LegacyChatPurge} dry run or real run.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Persistence;

/**
 * Immutable outcome: whether it succeeded (or would succeed), the
 * line-by-line log, and a one-line summary.
 */
final class LegacyChatPurgeReport {

	/**
	 * Constructor.
	 *
	 * @param bool               $ok      Whether the run succeeded / would succeed.
	 * @param array<int, string> $lines   The line-by-line log.
	 * @param string             $summary The one-line summary.
	 */
	public function __construct(
		private readonly bool $ok,
		private readonly array $lines,
		private readonly string $summary
	) {}

	/**
	 * Whether the run succeeded / would succeed.
	 *
	 * @return bool
	 */
	public function ok(): bool {
		return $this->ok;
	}

	/**
	 * The line-by-line log.
	 *
	 * @return array<int, string>
	 */
	public function lines(): array {
		return $this->lines;
	}

	/**
	 * The one-line summary.
	 *
	 * @return string
	 */
	public function summary(): string {
		return $this->summary;
	}
}
