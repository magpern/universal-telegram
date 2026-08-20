<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Queue;

use RuntimeException;
use UniversalTelegram\Persistence\MigrationFailureCode;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Queue\Dispatcher;
use UniversalTelegram\Queue\DispatchState;
use UniversalTelegram\Queue\FailureCode;
use UniversalTelegram\Queue\JobEnvelope;
use WP_UnitTestCase;

final class DispatcherTest extends WP_UnitTestCase {

	public function test_a_healthy_dispatch_schedules_a_real_action(): void {
		$dispatcher = new Dispatcher( new SchemaHealth() );
		$envelope   = new JobEnvelope( 'test.job', array(), array() );

		$result = $dispatcher->enqueue( $envelope );

		$this->assertSame( DispatchState::SCHEDULED, $result->state() );
		$this->assertGreaterThan( 0, $result->action_id() );
	}

	public function test_schema_unavailable_refuses_dispatch_without_calling_action_scheduler(): void {
		$schema_health = new SchemaHealth();
		$schema_health->mark_unavailable( MigrationFailureCode::STEP_FAILED );

		$dispatcher = new class( $schema_health ) extends Dispatcher {
			/**
			 * @var bool
			 */
			public bool $schedule_action_called = false;

			protected function schedule_action( array $args ) {
				$this->schedule_action_called = true;
				return 999;
			}
		};

		$result = $dispatcher->enqueue( new JobEnvelope( 'test.job', array(), array() ) );

		$this->assertSame( DispatchState::SCHEMA_UNAVAILABLE, $result->state() );
		$this->assertFalse( $dispatcher->schedule_action_called );
	}

	public function test_a_thrown_exception_results_in_failed_without_propagating(): void {
		$dispatcher = new class( new SchemaHealth() ) extends Dispatcher {
			protected function schedule_action( array $args ) {
				throw new RuntimeException( 'Simulated Action Scheduler failure.' );
			}
		};

		$result = $dispatcher->enqueue( new JobEnvelope( 'test.job', array(), array() ) );

		$this->assertSame( DispatchState::FAILED, $result->state() );
		$this->assertSame( FailureCode::DISPATCH_EXCEPTION, $result->failure_code() );
	}

	public function test_an_invalid_returned_action_id_results_in_failed_without_propagating(): void {
		$dispatcher = new class( new SchemaHealth() ) extends Dispatcher {
			protected function schedule_action( array $args ) {
				return 0;
			}
		};

		$result = $dispatcher->enqueue( new JobEnvelope( 'test.job', array(), array() ) );

		$this->assertSame( DispatchState::FAILED, $result->state() );
		$this->assertSame( FailureCode::DISPATCH_INVALID_ACTION_ID, $result->failure_code() );
	}
}
