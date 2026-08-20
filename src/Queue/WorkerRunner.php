<?php
/**
 * Job execution.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Queue;

use RuntimeException;
use Throwable;
use UniversalTelegram\Audit\AuditLogger;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Privacy\Classification;

/**
 * The single callback registered against the plugin's Action Scheduler
 * hook, in every request, regardless of schema availability. Checks
 * schema availability as the very first thing it does, before ever
 * looking up or invoking a job handler — a scheduled job encountered
 * while the schema is unavailable is always marked failed by Action
 * Scheduler, never silently complete (docs/adr/0006).
 */
final class WorkerRunner {

	public const HOOK  = 'universal_telegram_run_job';
	public const GROUP = 'universal-telegram';

	/**
	 * Checked first, before any handler.
	 *
	 * @var SchemaHealth
	 */
	private SchemaHealth $schema_health;

	/**
	 * Maps job_type to its handler.
	 *
	 * @var HandlerRegistry
	 */
	private HandlerRegistry $handler_registry;

	/**
	 * Decides whether and when to retry.
	 *
	 * @var RetryPolicy
	 */
	private RetryPolicy $retry_policy;

	/**
	 * Records attempt failures.
	 *
	 * @var AuditLogger
	 */
	private AuditLogger $audit_logger;

	/**
	 * Constructor.
	 *
	 * @param SchemaHealth    $schema_health    Checked first, before any handler.
	 * @param HandlerRegistry $handler_registry Maps job_type to its handler.
	 * @param RetryPolicy     $retry_policy     Decides whether and when to retry.
	 * @param AuditLogger     $audit_logger     Records attempt failures.
	 */
	public function __construct(
		SchemaHealth $schema_health,
		HandlerRegistry $handler_registry,
		RetryPolicy $retry_policy,
		AuditLogger $audit_logger
	) {
		$this->schema_health    = $schema_health;
		$this->handler_registry = $handler_registry;
		$this->retry_policy     = $retry_policy;
		$this->audit_logger     = $audit_logger;
	}

	/**
	 * The Action Scheduler hook callback.
	 *
	 * @param array{job_id: string, job_type: string, attempt: int, payload: array<string, mixed>} $job The job's own action arguments.
	 *
	 * @throws SchemaUnavailableException If the plugin's schema is unavailable.
	 * @throws RuntimeException If the job's own type has no registered handler.
	 * @throws Throwable Always rethrows a handler's own failure, so Action
	 *                     Scheduler's own bookkeeping for this attempt is
	 *                     correctly marked failed, never successful.
	 */
	public function run( array $job ): void {
		if ( ! $this->schema_health->is_available() ) {
			$this->handle_failure( $job );
			throw new SchemaUnavailableException( 'Schema unavailable.' );
		}

		$handler = $this->handler_registry->get( $job['job_type'] );

		if ( null === $handler ) {
			$this->handle_failure( $job );
			throw new RuntimeException( sprintf( 'Unknown job type "%s".', $job['job_type'] ) );
		}

		try {
			$handler( $job );
		} catch ( Throwable $exception ) {
			$this->handle_failure( $job );
			throw $exception;
		}
	}

	/**
	 * The four independently exception-guarded failure-handling steps.
	 * Never records the failure's own message or any payload value —
	 * only stable codes ever reach the audit log or the fallback logger.
	 *
	 * @param array{job_id: string, job_type: string, attempt: int, payload: array<string, mixed>} $job The failed job.
	 */
	private function handle_failure( array $job ): void {
		if ( ! $this->record( 'queue_job_attempt_failed', $job ) ) {
			$this->fallback_log( 'queue_job_attempt_failed' );
		}

		$should_retry = true;

		try {
			$should_retry = $this->retry_policy->should_retry( $job['attempt'] );
		} catch ( Throwable $policy_exception ) {
			$should_retry = true;
		}

		if ( $should_retry ) {
			$this->reschedule( $job );
			return;
		}

		if ( ! $this->record( 'queue_job_terminal_failure', $job ) ) {
			$this->fallback_log( 'queue_job_terminal_failure' );
		}
	}

	/**
	 * Attempts to schedule the next attempt at its computed backoff delay.
	 *
	 * @param array{job_id: string, job_type: string, attempt: int, payload: array<string, mixed>} $job The job to reschedule.
	 */
	private function reschedule( array $job ): void {
		$next_attempt = $job['attempt'] + 1;
		$timestamp    = $this->retry_policy->next_attempt_timestamp( $job['attempt'] );

		$args = array(
			array(
				'job_id'   => $job['job_id'],
				'job_type' => $job['job_type'],
				'attempt'  => $next_attempt,
				'payload'  => $job['payload'],
			),
		);

		$reschedule_failed = false;

		try {
			$action_id = as_schedule_single_action( $timestamp, self::HOOK, $args, self::GROUP );
		} catch ( Throwable $exception ) {
			$reschedule_failed = true;
			$action_id         = 0;
		}

		// Action Scheduler's own stub types this as always int, but its
		// documented behaviour returns 0 on several internal failure
		// paths; is_int() is kept for defensive robustness against a
		// future return-type change, matching Dispatcher's identical
		// guard.
		// phpcs:ignore Squiz.Commenting.InlineComment.InvalidEndChar
		// @phpstan-ignore function.alreadyNarrowedType
		if ( ! $reschedule_failed && ( ! is_int( $action_id ) || $action_id <= 0 ) ) {
			$reschedule_failed = true;
		}

		if ( $reschedule_failed && ! $this->record( 'queue_job_reschedule_failed', $job ) ) {
			$this->fallback_log( 'queue_job_reschedule_failed' );
		}
	}

	/**
	 * Records one internally classified audit entry for this job.
	 *
	 * @param string                                                                               $action The audit action name.
	 * @param array{job_id: string, job_type: string, attempt: int, payload: array<string, mixed>} $job    The job being recorded.
	 *
	 * @return bool
	 */
	private function record( string $action, array $job ): bool {
		return $this->audit_logger->record(
			$action,
			'system',
			null,
			array(
				'job_id'   => $job['job_id'],
				'job_type' => $job['job_type'],
				'attempt'  => $job['attempt'],
			),
			array(
				'job_id'   => Classification::INTERNAL,
				'job_type' => Classification::INTERNAL,
				'attempt'  => Classification::INTERNAL,
			),
			Classification::INTERNAL
		);
	}

	/**
	 * Guaranteed-non-throwing fallback logger, used only when AuditLogger's
	 * own write has itself failed. Carries only a stable code, never an
	 * exception message or a payload value.
	 *
	 * @param string $code The stable code to log.
	 */
	private function fallback_log( string $code ): void {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- deliberate last-resort fallback; see docblock.
		error_log( 'universal-telegram: ' . $code );
	}
}
