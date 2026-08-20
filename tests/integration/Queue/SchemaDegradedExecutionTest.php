<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Queue;

use ActionScheduler;
use ActionScheduler_QueueRunner;
use ActionScheduler_Store;
use UniversalTelegram\Audit\AuditLogger;
use UniversalTelegram\Core\Plugin;
use UniversalTelegram\Persistence\MigrationFailureCode;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Privacy\Redactor;
use UniversalTelegram\Queue\Dispatcher;
use UniversalTelegram\Queue\HandlerRegistry;
use UniversalTelegram\Queue\JobEnvelope;
use UniversalTelegram\Queue\RetryPolicy;
use UniversalTelegram\Queue\WorkerRunner;
use UniversalTelegram\Tests\Integration\Support\FailingJobFixture;
use WP_UnitTestCase;

/**
 * Direct test of the corrected degraded-mode design (docs/adr/0006): an
 * already-scheduled action encountered while the schema is unavailable
 * must be marked failed by Action Scheduler itself, not complete, must
 * never invoke its own handler, must remain eligible for the normal
 * bounded retry policy, and must execute normally once availability is
 * restored.
 */
final class SchemaDegradedExecutionTest extends WP_UnitTestCase {

	private const JOB_TYPE = 'test.degraded_job';

	protected function setUp(): void {
		parent::setUp();
		FailingJobFixture::reset();
	}

	public function test_full_degraded_and_recovery_lifecycle(): void {
		$real_worker_runner = Plugin::instance()->worker_runner();
		remove_action( WorkerRunner::HOOK, array( $real_worker_runner, 'run' ) );

		try {
			$schema_health    = new SchemaHealth();
			$handler_registry = new HandlerRegistry();
			$fixture          = new FailingJobFixture();
			$handler_registry->register( self::JOB_TYPE, $fixture );
			FailingJobFixture::$should_throw = false;

			$audit_logger = new AuditLogger( $schema_health, new Redactor() );
			$retry_policy = new RetryPolicy(
				static function (): int {
					return time();
				},
				static function ( int $max ): int {
					return 0;
				}
			);

			$test_worker_runner = new WorkerRunner( $schema_health, $handler_registry, $retry_policy, $audit_logger );
			add_action( WorkerRunner::HOOK, array( $test_worker_runner, 'run' ) );

			$dispatcher = new Dispatcher( $schema_health );
			$envelope   = new JobEnvelope( self::JOB_TYPE, array(), array(), 1, 'job-degraded' );
			$result     = $dispatcher->enqueue( $envelope );
			$action_id  = $result->action_id();
			$this->assertNotNull( $action_id );

			// Degrade the schema only now, after scheduling, to simulate a
			// migration failure discovered on a later request that
			// actually processes the already-scheduled action.
			$schema_health->mark_unavailable( MigrationFailureCode::STEP_FAILED );

			ActionScheduler_QueueRunner::instance()->run();

			$status = ActionScheduler::store()->get_status( $action_id );
			$this->assertSame( ActionScheduler_Store::STATUS_FAILED, $status );
			$this->assertSame( 0, FailingJobFixture::$invocation_count );

			// Remains eligible for the normal bounded retry policy.
			$this->assertNotFalse( as_next_scheduled_action( WorkerRunner::HOOK, null, WorkerRunner::GROUP ) );

			// Once schema availability is restored (a fresh SchemaHealth,
			// exactly as a new request would construct one), the retried
			// attempt executes normally.
			$restored_schema_health = new SchemaHealth();
			$restored_worker_runner = new WorkerRunner( $restored_schema_health, $handler_registry, $retry_policy, $audit_logger );

			$restored_worker_runner->run(
				array(
					'job_id'   => 'job-degraded',
					'job_type' => self::JOB_TYPE,
					'attempt'  => 2,
					'payload'  => array(),
				)
			);

			$this->assertSame( 1, FailingJobFixture::$invocation_count );
		} finally {
			remove_action( WorkerRunner::HOOK, array( $test_worker_runner ?? null, 'run' ) );
			add_action( WorkerRunner::HOOK, array( $real_worker_runner, 'run' ) );
		}
	}
}
