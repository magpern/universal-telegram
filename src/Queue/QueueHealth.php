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
	 * How long, in seconds, the single oldest currently-pending action in
	 * the plugin's own group has been due. Zero when no action is pending.
	 * Diagnostic only — the actual evidence of whether expedited dispatch
	 * (docs/adr/0023) is helping is this value combined with a message's
	 * own recorded delivery outcome, never a request-time signal alone.
	 *
	 * @return int
	 */
	public function oldest_pending_age_seconds(): int {
		if ( ! class_exists( ActionScheduler::class ) ) {
			return 0;
		}

		$oldest = ActionScheduler::store()->query_actions(
			array(
				'group'    => WorkerRunner::GROUP,
				'status'   => ActionScheduler_Store::STATUS_PENDING,
				'orderby'  => 'date',
				'order'    => 'ASC',
				'per_page' => 1,
			)
		);

		if ( empty( $oldest ) ) {
			return 0;
		}

		$action_id = is_array( $oldest ) ? reset( $oldest ) : null;

		if ( null === $action_id ) {
			return 0;
		}

		$scheduled_date = ActionScheduler::store()->get_date( (int) $action_id );

		if ( null === $scheduled_date ) {
			return 0;
		}

		$age = time() - $scheduled_date->getTimestamp();

		return max( 0, $age );
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
