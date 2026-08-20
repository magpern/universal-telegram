<?php
/**
 * Failed Action Scheduler action event emission.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Events\Emitters;

use ActionScheduler;
use UniversalTelegram\Events\Registry;
use UniversalTelegram\Privacy\Classification;
use UniversalTelegram\Queue\WorkerRunner;

/**
 * A thin, reviewed callback on action_scheduler_failed_action, excluding
 * any failed action in this plugin's own queue group (M02 plan §8.7): if
 * the Telegram transport itself is the thing failing, attempting to notify
 * about that failure via Telegram is pointless or counterproductive, and
 * this class of failure is already fully surfaced by M01's own existing,
 * unchanged diagnostics mechanism.
 */
final class ScheduledTaskFailureEmitter {

	public const SCHEDULED_TASK_FAILED = 'wordpress.scheduled_task_failed';

	/**
	 * Registers this emitter's event type.
	 *
	 * @param Registry $registry The current request's event registry.
	 */
	public function register_event_types( Registry $registry ): void {
		$fields = array(
			'payload.action_id' => Classification::PUBLIC,
			'payload.group'     => Classification::PUBLIC,
			'payload.hook'      => Classification::PUBLIC,
		);

		$registry->register( self::SCHEDULED_TASK_FAILED, 1, $fields, array_keys( $fields ), array_keys( $fields ) );
	}

	/**
	 * The action_scheduler_failed_action callback.
	 *
	 * @param int   $action_id The failed action's ID.
	 * @param mixed $error     The failure detail. Never read.
	 */
	public function on_action_failed( int $action_id, $error = null ): void {
		if ( ! class_exists( ActionScheduler::class ) ) {
			return;
		}

		try {
			$action = ActionScheduler::store()->fetch_action( $action_id );
		} catch ( \Throwable $exception ) {
			return;
		}

		$group = $action->get_group();

		// The one, unconditional, non-configurable feedback-loop exclusion
		// for this emitter: never emit for a failure inside this plugin's
		// own queue group.
		if ( WorkerRunner::GROUP === $group ) {
			return;
		}

		universal_telegram_emit_event(
			self::SCHEDULED_TASK_FAILED,
			array(
				'payload' => array(
					'action_id' => $action_id,
					'group'     => $group,
					'hook'      => $action->get_hook(),
				),
			),
			hash( 'sha256', 'as_failed:' . $action_id )
		);
	}
}
