<?php
/**
 * Port over Action Scheduler for the digest chain.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Integrations\FluentContactInbox\Digest;

/**
 * Production adapter: {@see ActionSchedulerDigestScheduler}.
 */
interface DigestActionScheduler {

	/**
	 * Whether a future occurrence is already pending.
	 *
	 * @return bool
	 */
	public function has_pending(): bool;

	/**
	 * Cancels every pending occurrence of the digest hook.
	 */
	public function unschedule_all(): void;

	/**
	 * Schedules exactly one occurrence.
	 *
	 * @param int $timestamp UTC timestamp.
	 */
	public function schedule_single( int $timestamp ): void;
}
