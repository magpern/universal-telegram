<?php
/**
 * Queue health surface.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Queue;

use ActionScheduler;
use ActionScheduler_Store;

/**
 * Pending and failed action counts, scoped to the plugin's own Action
 * Scheduler group, for use by the diagnostics surface.
 */
final class QueueHealth {

	/**
	 * The number of currently pending actions.
	 *
	 * @return int
	 */
	public function pending_count(): int {
		return $this->count_by_status( array( ActionScheduler_Store::STATUS_PENDING ) );
	}

	/**
	 * The number of currently failed actions.
	 *
	 * @return int
	 */
	public function failed_count(): int {
		return $this->count_by_status( array( ActionScheduler_Store::STATUS_FAILED ) );
	}

	/**
	 * Counts actions in the plugin's own group matching the given statuses.
	 *
	 * @param array<int, string> $statuses The statuses to count.
	 *
	 * @return int
	 */
	private function count_by_status( array $statuses ): int {
		if ( ! class_exists( ActionScheduler::class ) ) {
			return 0;
		}

		return (int) ActionScheduler::store()->query_actions(
			array(
				'group'  => WorkerRunner::GROUP,
				'status' => $statuses,
			),
			'count'
		);
	}
}
