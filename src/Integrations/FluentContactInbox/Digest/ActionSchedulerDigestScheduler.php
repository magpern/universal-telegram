<?php
/**
 * Action Scheduler adapter for the digest chain.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Integrations\FluentContactInbox\Digest;

use UniversalTelegram\Queue\WorkerRunner;

/**
 * One-shot occurrences in the plugin's own Action Scheduler group.
 */
final class ActionSchedulerDigestScheduler implements DigestActionScheduler {

	/**
	 * {@inheritDoc}
	 */
	public function has_pending(): bool {
		return function_exists( 'as_has_scheduled_action' )
			&& as_has_scheduled_action( TicketDigestJob::HOOK, array(), WorkerRunner::GROUP );
	}

	/**
	 * {@inheritDoc}
	 */
	public function unschedule_all(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( TicketDigestJob::HOOK, array(), WorkerRunner::GROUP );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $timestamp UTC timestamp.
	 */
	public function schedule_single( int $timestamp ): void {
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( $timestamp, TicketDigestJob::HOOK, array(), WorkerRunner::GROUP );
		}
	}
}
