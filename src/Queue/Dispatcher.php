<?php
/**
 * Job dispatch.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Queue;

use Throwable;
use UniversalTelegram\Persistence\SchemaHealth;

/**
 * Never blocks or breaks the originating WordPress request under any
 * circumstance: returns a DispatchResult, never throws.
 *
 * Not declared final: tests/integration/Queue/DispatcherTest overrides
 * schedule_action() to deterministically force both a thrown exception and
 * an invalid returned action ID, without needing to break Action
 * Scheduler's own tables to reproduce a real failure. Production code
 * never overrides it.
 */
class Dispatcher {

	/**
	 * How far in the past an `interactive_chat` action is scheduled so
	 * Action Scheduler — which claims due actions in `scheduled_date ASC,
	 * action_id ASC` order — runs it ahead of freshly-enqueued `standard`
	 * work, while `scheduled_date` monotonicity keeps FIFO within the
	 * interactive class (docs/adr/0045 §3). Deliberately far larger than
	 * any healthy queue's oldest pending `standard` action.
	 *
	 * @var int
	 */
	private const INTERACTIVE_PRIORITY_LEAD_SECONDS = 86400;

	/**
	 * Checked before ever calling Action Scheduler.
	 *
	 * @var SchemaHealth
	 */
	private SchemaHealth $schema_health;

	/**
	 * Constructor.
	 *
	 * @param SchemaHealth $schema_health Checked before ever calling Action Scheduler.
	 */
	public function __construct( SchemaHealth $schema_health ) {
		$this->schema_health = $schema_health;
	}

	/**
	 * Schedules a job for asynchronous execution.
	 *
	 * @param JobEnvelope $envelope The job to schedule.
	 *
	 * @return DispatchResult
	 */
	public function enqueue( JobEnvelope $envelope ): DispatchResult {
		if ( ! $this->schema_health->is_available() ) {
			return DispatchResult::schema_unavailable();
		}

		$args = array( $envelope->to_action_args() );

		$payload        = $envelope->payload();
		$delivery_class = isset( $payload['delivery_class'] ) && is_string( $payload['delivery_class'] )
			? $payload['delivery_class']
			: DeliveryClass::STANDARD;

		try {
			$action_id = DeliveryClass::INTERACTIVE_CHAT === $delivery_class
				? $this->schedule_interactive_action( $args )
				: $this->schedule_action( $args );
		} catch ( Throwable $exception ) {
			return DispatchResult::failed( FailureCode::DISPATCH_EXCEPTION );
		}

		if ( ! is_int( $action_id ) || $action_id <= 0 ) {
			return DispatchResult::failed( FailureCode::DISPATCH_INVALID_ACTION_ID );
		}

		return DispatchResult::scheduled( $action_id );
	}

	/**
	 * Calls Action Scheduler's own async-enqueue function. Overridable by
	 * tests; see the class docblock.
	 *
	 * @param array<int, mixed> $args The Action Scheduler action arguments.
	 *
	 * @return int|bool
	 */
	protected function schedule_action( array $args ) {
		return as_enqueue_async_action( WorkerRunner::HOOK, $args, WorkerRunner::GROUP );
	}

	/**
	 * Schedules an `interactive_chat` job with an earlier `scheduled_date`
	 * so Action Scheduler claims it ahead of ordinary work (docs/adr/0045
	 * §3). Same hook, same group, same handler — only queue position
	 * changes. Overridable by tests; production code never overrides it.
	 *
	 * @param array<int, mixed> $args The Action Scheduler action arguments.
	 *
	 * @return int|bool
	 */
	protected function schedule_interactive_action( array $args ) {
		return as_schedule_single_action(
			time() - self::INTERACTIVE_PRIORITY_LEAD_SECONDS,
			WorkerRunner::HOOK,
			$args,
			WorkerRunner::GROUP
		);
	}
}
