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

		try {
			$action_id = $this->schedule_action( array( $envelope->to_action_args() ) );
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
}
