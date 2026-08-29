<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Queue;

use RuntimeException;
use UniversalTelegram\Persistence\MigrationFailureCode;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Queue\DeliveryClass;
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

	public function test_standard_envelope_uses_the_ordinary_enqueue_and_not_the_interactive_seam(): void {
		$dispatcher = new class( new SchemaHealth() ) extends Dispatcher {
			public bool $standard_called    = false;
			public bool $interactive_called = false;

			protected function schedule_action( array $args ) {
				$this->standard_called = true;
				return 101;
			}

			protected function schedule_interactive_action( array $args ) {
				$this->interactive_called = true;
				return 202;
			}
		};

		$result = $dispatcher->enqueue( new JobEnvelope( 'test.job', array( 'delivery_class' => DeliveryClass::STANDARD ), array( 'delivery_class' => \UniversalTelegram\Privacy\Classification::INTERNAL ) ) );

		$this->assertSame( DispatchState::SCHEDULED, $result->state() );
		$this->assertTrue( $dispatcher->standard_called );
		$this->assertFalse( $dispatcher->interactive_called );
	}

	public function test_a_missing_delivery_class_is_treated_as_standard(): void {
		$dispatcher = new class( new SchemaHealth() ) extends Dispatcher {
			public bool $interactive_called = false;

			protected function schedule_action( array $args ) {
				return 101;
			}

			protected function schedule_interactive_action( array $args ) {
				$this->interactive_called = true;
				return 202;
			}
		};

		$dispatcher->enqueue( new JobEnvelope( 'test.job', array(), array() ) );

		$this->assertFalse( $dispatcher->interactive_called );
	}

	public function test_interactive_chat_envelope_is_routed_through_the_interactive_seam(): void {
		$dispatcher = new class( new SchemaHealth() ) extends Dispatcher {
			public bool $standard_called    = false;
			public bool $interactive_called = false;

			protected function schedule_action( array $args ) {
				$this->standard_called = true;
				return 101;
			}

			protected function schedule_interactive_action( array $args ) {
				$this->interactive_called = true;
				return 303;
			}
		};

		$result = $dispatcher->enqueue(
			new JobEnvelope(
				'test.job',
				array( 'delivery_class' => DeliveryClass::INTERACTIVE_CHAT ),
				array( 'delivery_class' => \UniversalTelegram\Privacy\Classification::INTERNAL )
			)
		);

		$this->assertSame( DispatchState::SCHEDULED, $result->state() );
		$this->assertSame( 303, $result->action_id() );
		$this->assertTrue( $dispatcher->interactive_called );
		$this->assertFalse( $dispatcher->standard_called );
	}

	public function test_interactive_actions_are_claimed_before_standard_and_stay_fifo_within_class(): void {
		if ( ! function_exists( 'as_schedule_single_action' ) || ! class_exists( \ActionScheduler_Store::class ) ) {
			$this->markTestSkipped( 'Action Scheduler is not available.' );
		}

		$dispatcher = new Dispatcher( new SchemaHealth() );

		$make = static fn ( string $id, string $dc ): JobEnvelope => new JobEnvelope(
			'test.order.' . $id,
			array( 'delivery_class' => $dc ),
			array( 'delivery_class' => \UniversalTelegram\Privacy\Classification::INTERNAL ),
			1,
			'order-' . $id
		);

		// Enqueue order: standard #1, interactive #1, standard #2, interactive #2.
		$dispatcher->enqueue( $make( 's1', DeliveryClass::STANDARD ) );
		$dispatcher->enqueue( $make( 'i1', DeliveryClass::INTERACTIVE_CHAT ) );
		$dispatcher->enqueue( $make( 's2', DeliveryClass::STANDARD ) );
		$dispatcher->enqueue( $make( 'i2', DeliveryClass::INTERACTIVE_CHAT ) );

		$store = \ActionScheduler::store();
		$ids   = $store->query_actions(
			array(
				'hook'     => \UniversalTelegram\Queue\WorkerRunner::HOOK,
				'group'    => \UniversalTelegram\Queue\WorkerRunner::GROUP,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'orderby'  => 'date',
				'order'    => 'ASC',
				'per_page' => 50,
			)
		);

		$order = array();
		foreach ( $ids as $action_id ) {
			$order[] = $store->fetch_action( $action_id )->get_args()[0]['job_id'];
		}

		$order = array_values( array_intersect( $order, array( 'order-s1', 'order-i1', 'order-s2', 'order-i2' ) ) );

		// Both interactive actions are claimed before either standard one,
		// and FIFO holds within each class.
		$this->assertSame( array( 'order-i1', 'order-i2', 'order-s1', 'order-s2' ), $order );
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
