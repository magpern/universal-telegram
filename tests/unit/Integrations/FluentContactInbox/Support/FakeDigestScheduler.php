<?php
/**
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Tests\Integrations\FluentContactInbox\Support;

use UniversalTelegram\Integrations\FluentContactInbox\Digest\DigestActionScheduler;

/**
 * In-memory Action Scheduler stand-in.
 */
final class FakeDigestScheduler implements DigestActionScheduler {

	/**
	 * Pending occurrences.
	 *
	 * @var array<int, int>
	 */
	public array $pending = array();

	/**
	 * {@inheritDoc}
	 */
	public function has_pending(): bool {
		return array() !== $this->pending;
	}

	/**
	 * {@inheritDoc}
	 */
	public function unschedule_all(): void {
		$this->pending = array();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $timestamp Timestamp.
	 */
	public function schedule_single( int $timestamp ): void {
		$this->pending[] = $timestamp;
	}
}
