<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Queue;

use RuntimeException;
use UniversalTelegram\Audit\AuditLogger;
use UniversalTelegram\Audit\AuditLogRepository;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Privacy\Redactor;
use UniversalTelegram\Queue\HandlerRegistry;
use UniversalTelegram\Queue\RetryPolicy;
use UniversalTelegram\Queue\WorkerRunner;
use UniversalTelegram\Tests\Integration\Support\FailingJobFixture;
use WP_UnitTestCase;

final class WorkerRunnerTest extends WP_UnitTestCase {

	private const JOB_TYPE = 'test.failing_job';

	protected function setUp(): void {
		parent::setUp();
		FailingJobFixture::reset();
	}

	/**
	 * @return array{0: WorkerRunner, 1: AuditLogRepository}
	 */
	private function make_runner(): array {
		$schema_health = new SchemaHealth();

		$handler_registry = new HandlerRegistry();
		$handler_registry->register( self::JOB_TYPE, new FailingJobFixture() );

		$audit_logger = new AuditLogger( $schema_health, new Redactor() );
		$repository   = new AuditLogRepository( $schema_health );

		$retry_policy = new RetryPolicy(
			static function (): int {
				return 1000;
			},
			static function ( int $max ): int {
				return 0;
			}
		);

		$runner = new WorkerRunner( $schema_health, $handler_registry, $retry_policy, $audit_logger );

		return array( $runner, $repository );
	}

	/**
	 * @param array<int, array<string, mixed>> $entries The recent audit entries.
	 * @param string                           $action  The action name to count.
	 */
	private function count_action( array $entries, string $action ): int {
		$matching = array_filter(
			$entries,
			static function ( array $entry ) use ( $action ): bool {
				return $action === $entry['action'];
			}
		);

		return count( $matching );
	}

	public function test_a_failed_attempt_records_one_entry_and_reschedules_at_the_computed_delay(): void {
		list( $runner, $repository ) = $this->make_runner();

		$job = array(
			'job_id'   => 'job-attempt-1',
			'job_type' => self::JOB_TYPE,
			'attempt'  => 1,
			'payload'  => array(),
		);

		try {
			$runner->run( $job );
			$this->fail( 'Expected the original exception to be rethrown.' );
		} catch ( RuntimeException $exception ) {
			$this->assertSame( 'Deliberate test failure.', $exception->getMessage() );
		}

		$this->assertSame( 1, FailingJobFixture::$invocation_count );

		$entries = $repository->recent( 10 );
		$this->assertSame( 1, $this->count_action( $entries, 'queue_job_attempt_failed' ) );
		$this->assertSame( 0, $this->count_action( $entries, 'queue_job_terminal_failure' ) );

		// Clock is fixed at 1000, zero jitter: attempt 1's delay is 30.
		$next_run = as_next_scheduled_action( WorkerRunner::HOOK, null, WorkerRunner::GROUP );
		$this->assertSame( 1030, $next_run );
	}

	public function test_a_terminal_failure_is_recorded_once_the_maximum_attempt_is_exhausted(): void {
		list( $runner, $repository ) = $this->make_runner();

		$job = array(
			'job_id'   => 'job-attempt-5',
			'job_type' => self::JOB_TYPE,
			'attempt'  => 5,
			'payload'  => array(),
		);

		try {
			$runner->run( $job );
			$this->fail( 'Expected the original exception to be rethrown.' );
		} catch ( RuntimeException $exception ) {
			$this->assertSame( 'Deliberate test failure.', $exception->getMessage() );
		}

		$entries = $repository->recent( 10 );
		$this->assertSame( 1, $this->count_action( $entries, 'queue_job_attempt_failed' ) );
		$this->assertSame( 1, $this->count_action( $entries, 'queue_job_terminal_failure' ) );

		$this->assertFalse( as_next_scheduled_action( WorkerRunner::HOOK, null, WorkerRunner::GROUP ) );
	}

	public function test_a_successful_attempt_neither_throws_nor_records_a_failure(): void {
		list( $runner, $repository )     = $this->make_runner();
		FailingJobFixture::$should_throw = false;

		$job = array(
			'job_id'   => 'job-success',
			'job_type' => self::JOB_TYPE,
			'attempt'  => 1,
			'payload'  => array(),
		);

		$runner->run( $job );

		$this->assertSame( 1, FailingJobFixture::$invocation_count );

		$entries = $repository->recent( 10 );
		$this->assertSame( 0, $this->count_action( $entries, 'queue_job_attempt_failed' ) );
	}
}
