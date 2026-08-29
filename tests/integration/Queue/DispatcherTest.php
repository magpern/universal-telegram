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
		$s1 = (int) $dispatcher->enqueue( $make( 's1', DeliveryClass::STANDARD ) )->action_id();
		$i1 = (int) $dispatcher->enqueue( $make( 'i1', DeliveryClass::INTERACTIVE_CHAT ) )->action_id();
		$s2 = (int) $dispatcher->enqueue( $make( 's2', DeliveryClass::STANDARD ) )->action_id();
		$i2 = (int) $dispatcher->enqueue( $make( 'i2', DeliveryClass::INTERACTIVE_CHAT ) )->action_id();

		$store = \ActionScheduler::store();
		$at    = static function ( int $action_id ) use ( $store ): int {
			$date = $store->fetch_action( $action_id )->get_schedule()->get_date();

			// An `as_enqueue_async_action` job has no scheduled date (it runs
			// ASAP, effectively "now"); an interactive job is deliberately
			// past-dated.
			return null === $date ? time() : $date->getTimestamp();
		};

		$now = time();

		// The mechanism (docs/adr/0045 §3): interactive actions are dated far
		// in the past so Action Scheduler — which claims `scheduled_date ASC,
		// action_id ASC` — takes them ahead of ordinary work.
		$this->assertLessThan( $now - 3600, $at( $i1 ), 'interactive is past-dated for priority' );
		$this->assertLessThan( $now - 3600, $at( $i2 ) );

		// Standard actions are never past-dated for priority.
		$this->assertGreaterThanOrEqual( $now - 60, $at( $s1 ) );
		$this->assertGreaterThanOrEqual( $now - 60, $at( $s2 ) );

		// So every interactive action sorts before every standard one.
		$this->assertLessThan( $at( $s1 ), $at( $i1 ) );
		$this->assertLessThan( $at( $s2 ), $at( $i2 ) );

		// FIFO within the interactive class: same-second dates, so the
		// earlier-enqueued action carries the lower action_id — the exact
		// tiebreak Action Scheduler's claim query uses.
		$this->assertLessThanOrEqual( $at( $i2 ), $at( $i1 ) );
		$this->assertLessThan( $i2, $i1 );

		// FIFO within the standard class, unchanged.
		$this->assertLessThan( $s2, $s1 );
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
