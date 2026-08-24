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

		// Action Scheduler enforces a max-one-concurrent-batch guard keyed
		// on live rows in its own actionscheduler_claims table
		// (ActionScheduler_Abstract_QueueRunner::has_maximum_concurrent_batches()).
		// A claim row left behind by any other test in the same suite run
		// (e.g. one that calls ActionScheduler_QueueRunner::run() and does
		// not fully release its claim before the test's transaction is
		// rolled back) silently makes every subsequent call to run() in
		// this process a no-op — this test's own action would then never
		// be claimed at all, regardless of this milestone's production
		// code. Clearing any pre-existing claim rows here is test-hygiene
		// only: it does not change what this test asserts, only guards its
		// own isolation against unrelated test-ordering effects elsewhere
		// in the suite (M03 validation-gate fix; ADR-0006/ADR-0007 remain
		// unchanged, no production code touched).
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->actionscheduler_claims}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		// Action Scheduler's own internal store-migration housekeeping
		// action (action_scheduler/migration_hook, scheduled once by
		// Action Scheduler itself, unrelated to this plugin) can be due at
		// the same moment as this test's own action once WooCommerce is
		// active and the full suite has run long enough for it to become
		// due. When claimed in the same batch, running it consumes this
		// run() call without this test's own action being processed
		// afterwards, making this test's specific queue-runner assertion
		// order-dependent on Action Scheduler's own unrelated internal
		// migration state rather than on the schema-degraded behaviour
		// this test exists to verify. Cancelling it here is test-hygiene
		// only — it never touches this plugin's own production code.
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'action_scheduler/migration_hook' );

			// Same root cause, for M09's own recurring stale-lease sweep
			// (docs/adr/0028 §3.5): AiDraftLeaseSweep::register() schedules
			// it once, immediately due, when the plugin bootstraps for the
			// whole test run — otherwise it can be claimed in the same
			// batch as this test's own action, consuming a run() call
			// this test's queue-runner assertion depends on.
			as_unschedule_all_actions( \UniversalTelegram\AI\Draft\AiDraftLeaseSweep::JOB_TYPE );
			as_unschedule_all_actions( \UniversalTelegram\Automations\Intelligence\SummaryAiLeaseSweep::JOB_TYPE );
			as_unschedule_all_actions( \UniversalTelegram\Automations\Digest\VisitorDigestSweep::JOB_TYPE );
			as_unschedule_all_actions( \UniversalTelegram\Automations\Intelligence\OperationalSummarySweep::JOB_TYPE );
		}
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
