<?php
/**
 * Dispatch outcome.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Queue;

/**
 * Returned by Dispatcher::enqueue(). Never thrown, never void: dispatch
 * failure is always reported as a value, never as an exception that could
 * break the originating request.
 */
final class DispatchResult {

	/**
	 * The outcome.
	 *
	 * @var DispatchState
	 */
	private DispatchState $state;

	/**
	 * The scheduled Action Scheduler action ID, only when scheduled.
	 *
	 * @var int|null
	 */
	private ?int $action_id;

	/**
	 * The stable failure code, only when failed.
	 *
	 * @var FailureCode|null
	 */
	private ?FailureCode $failure_code;

	/**
	 * Constructor. Use the named constructors below instead.
	 *
	 * @param DispatchState    $state        The outcome.
	 * @param int|null         $action_id    The scheduled Action Scheduler
	 *                                        action ID, only when scheduled.
	 * @param FailureCode|null $failure_code The stable failure code, only
	 *                                        when failed.
	 */
	private function __construct( DispatchState $state, ?int $action_id, ?FailureCode $failure_code ) {
		$this->state        = $state;
		$this->action_id    = $action_id;
		$this->failure_code = $failure_code;
	}

	/**
	 * A job was successfully scheduled.
	 *
	 * @param int $action_id The scheduled Action Scheduler action ID.
	 *
	 * @return self
	 */
	public static function scheduled( int $action_id ): self {
		return new self( DispatchState::SCHEDULED, $action_id, null );
	}

	/**
	 * The plugin's schema is currently unavailable.
	 *
	 * @return self
	 */
	public static function schema_unavailable(): self {
		return new self( DispatchState::SCHEMA_UNAVAILABLE, null, null );
	}

	/**
	 * Action Scheduler itself failed to schedule the job.
	 *
	 * @param FailureCode $failure_code The stable failure code.
	 *
	 * @return self
	 */
	public static function failed( FailureCode $failure_code ): self {
		return new self( DispatchState::FAILED, null, $failure_code );
	}

	/**
	 * The outcome.
	 *
	 * @return DispatchState
	 */
	public function state(): DispatchState {
		return $this->state;
	}

	/**
	 * The scheduled Action Scheduler action ID, only when scheduled.
	 *
	 * @return int|null
	 */
	public function action_id(): ?int {
		return $this->action_id;
	}

	/**
	 * The stable failure code, only when failed.
	 *
	 * @return FailureCode|null
	 */
	public function failure_code(): ?FailureCode {
		return $this->failure_code;
	}
}
