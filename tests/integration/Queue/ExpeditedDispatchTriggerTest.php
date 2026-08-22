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
use UniversalTelegram\Queue\ExpeditedDispatchTrigger;
use WP_UnitTestCase;

final class ExpeditedDispatchTriggerTest extends WP_UnitTestCase {

	/**
	 * @return array{0: SchemaHealth, 1: AuditLogRepository}
	 */
	private function make_dependencies(): array {
		$schema_health = new SchemaHealth();

		return array( $schema_health, new AuditLogRepository( $schema_health ) );
	}

	public function test_a_healthy_dependency_and_available_batch_issues_the_call_without_auditing_anything(): void {
		list( $schema_health, $repository ) = $this->make_dependencies();
		$audit                              = new AuditLogger( $schema_health, new Redactor() );

		$trigger = new class( $audit ) extends ExpeditedDispatchTrigger {
			public int $maybe_dispatch_calls = 0;

			protected function dependency_available(): bool {
				return true;
			}

			protected function declined_for_concurrency(): bool {
				return false;
			}

			protected function create_runner() {
				$owner = $this;

				return new class( $owner ) {
					public function __construct( private $owner ) {}

					public function maybe_dispatch(): void {
						++$this->owner->maybe_dispatch_calls;
					}
				};
			}
		};

		$trigger->trigger();

		$this->assertSame( 1, $trigger->maybe_dispatch_calls );
		$this->assertSame( 0, $repository->count_by_action_24h( 'expedited_dispatch_declined_concurrency' ) );
		$this->assertSame( 0, $repository->count_by_action_24h( 'expedited_dispatch_unavailable' ) );
	}

	public function test_missing_dependency_is_audited_as_unavailable_and_never_constructs_a_runner(): void {
		list( $schema_health, $repository ) = $this->make_dependencies();
		$audit                              = new AuditLogger( $schema_health, new Redactor() );

		$trigger = new class( $audit ) extends ExpeditedDispatchTrigger {
			public bool $create_runner_called = false;

			protected function dependency_available(): bool {
				return false;
			}

			protected function create_runner() {
				$this->create_runner_called = true;
				return new \stdClass();
			}
		};

		$trigger->trigger();

		$this->assertFalse( $trigger->create_runner_called );
		$this->assertSame( 1, $repository->count_by_action_24h( 'expedited_dispatch_unavailable' ) );
	}

	public function test_declined_concurrency_is_audited_and_never_constructs_a_runner(): void {
		list( $schema_health, $repository ) = $this->make_dependencies();
		$audit                              = new AuditLogger( $schema_health, new Redactor() );

		$trigger = new class( $audit ) extends ExpeditedDispatchTrigger {
			public bool $create_runner_called = false;

			protected function dependency_available(): bool {
				return true;
			}

			protected function declined_for_concurrency(): bool {
				return true;
			}

			protected function create_runner() {
				$this->create_runner_called = true;
				return new \stdClass();
			}
		};

		$trigger->trigger();

		$this->assertFalse( $trigger->create_runner_called );
		$this->assertSame( 1, $repository->count_by_action_24h( 'expedited_dispatch_declined_concurrency' ) );
		$this->assertSame( 0, $repository->count_by_action_24h( 'expedited_dispatch_unavailable' ) );
	}

	public function test_a_runner_missing_maybe_dispatch_is_audited_as_unavailable(): void {
		list( $schema_health, $repository ) = $this->make_dependencies();
		$audit                              = new AuditLogger( $schema_health, new Redactor() );

		$trigger = new class( $audit ) extends ExpeditedDispatchTrigger {
			protected function dependency_available(): bool {
				return true;
			}

			protected function declined_for_concurrency(): bool {
				return false;
			}

			protected function create_runner() {
				// Simulates a future incompatible Action Scheduler version:
				// an object without maybe_dispatch(). A declared return
				// type on create_runner() would make this impossible to
				// express, which is exactly the seam this test exercises.
				return new \stdClass();
			}
		};

		$trigger->trigger();

		$this->assertSame( 1, $repository->count_by_action_24h( 'expedited_dispatch_unavailable' ) );
	}

	public function test_a_thrown_construction_failure_is_caught_and_audited_as_unavailable(): void {
		list( $schema_health, $repository ) = $this->make_dependencies();
		$audit                              = new AuditLogger( $schema_health, new Redactor() );

		$trigger = new class( $audit ) extends ExpeditedDispatchTrigger {
			protected function dependency_available(): bool {
				return true;
			}

			protected function declined_for_concurrency(): bool {
				return false;
			}

			protected function create_runner() {
				throw new RuntimeException( 'Simulated construction failure.' );
			}
		};

		// No exception should escape trigger() itself.
		$trigger->trigger();

		$this->assertSame( 1, $repository->count_by_action_24h( 'expedited_dispatch_unavailable' ) );
	}

	public function test_a_thrown_invocation_failure_is_caught_and_audited_as_unavailable(): void {
		list( $schema_health, $repository ) = $this->make_dependencies();
		$audit                              = new AuditLogger( $schema_health, new Redactor() );

		$trigger = new class( $audit ) extends ExpeditedDispatchTrigger {
			protected function dependency_available(): bool {
				return true;
			}

			protected function declined_for_concurrency(): bool {
				return false;
			}

			protected function create_runner() {
				return new class() {
					public function maybe_dispatch(): void {
						throw new RuntimeException( 'Simulated loopback invocation failure.' );
					}
				};
			}
		};

		$trigger->trigger();

		$this->assertSame( 1, $repository->count_by_action_24h( 'expedited_dispatch_unavailable' ) );
	}
}
