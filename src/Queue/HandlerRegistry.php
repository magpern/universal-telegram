<?php
/**
 * Job handler registry.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Queue;

/**
 * Internal job_type-to-handler map, populated only by Core\Plugin::init()
 * itself. Explicitly not a public extension point: no hook exists for a
 * third party to register a handler through it.
 */
final class HandlerRegistry {

	/**
	 * Maps a job type to the callable that handles it. Each callable
	 * receives the job's payload array and returns void.
	 *
	 * @var array<string, callable>
	 */
	private array $handlers = array();

	/**
	 * Registers the handler for a job type.
	 *
	 * @param string   $job_type The job type this handler owns.
	 * @param callable $handler  Receives the job's payload array.
	 */
	public function register( string $job_type, callable $handler ): void {
		$this->handlers[ $job_type ] = $handler;
	}

	/**
	 * Looks up the handler for a job type.
	 *
	 * @param string $job_type The job type to look up.
	 *
	 * @return callable|null
	 */
	public function get( string $job_type ): ?callable {
		return $this->handlers[ $job_type ] ?? null;
	}
}
