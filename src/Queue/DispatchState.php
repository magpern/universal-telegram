<?php
/**
 * Dispatch outcome states.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Queue;

/**
 * The four possible outcomes of Dispatcher::enqueue().
 */
enum DispatchState {
	/**
	 * The job was successfully scheduled.
	 */
	case SCHEDULED;

	/**
	 * Never reached in practice — JobEnvelope's own constructor already
	 * rejects an unsafe payload before a Dispatcher call can happen. Kept
	 * as part of the documented contract for completeness.
	 */
	case REJECTED_PAYLOAD;

	/**
	 * The plugin's schema is currently unavailable; dispatch was refused
	 * without ever calling into Action Scheduler.
	 */
	case SCHEMA_UNAVAILABLE;

	/**
	 * Action Scheduler itself failed to schedule the job.
	 */
	case FAILED;
}
