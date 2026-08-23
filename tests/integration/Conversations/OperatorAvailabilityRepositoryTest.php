<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Conversations;

use UniversalTelegram\Conversations\OperatorAvailability;
use UniversalTelegram\Conversations\OperatorAvailabilityRepository;
use UniversalTelegram\Persistence\SchemaHealth;
use WP_UnitTestCase;

final class OperatorAvailabilityRepositoryTest extends WP_UnitTestCase {

	private OperatorAvailabilityRepository $repository;

	protected function setUp(): void {
		parent::setUp();

		$this->repository = new OperatorAvailabilityRepository( new SchemaHealth() );
	}

	public function test_find_for_operator_with_no_row_returns_null(): void {
		$this->assertNull( $this->repository->find_for_operator( 42 ) );
	}

	public function test_set_state_creates_the_row_on_first_use(): void {
		$state = $this->repository->set_state( 42, OperatorAvailability::AVAILABLE, 42 );

		$this->assertNotNull( $state );
		$this->assertSame( OperatorAvailability::AVAILABLE, $state->state() );
		$this->assertSame( 42, $state->operator_user_id() );
		$this->assertSame( 42, $state->updated_by() );
	}

	public function test_set_state_updates_an_existing_row(): void {
		$this->repository->set_state( 42, OperatorAvailability::AVAILABLE, 42 );
		$updated = $this->repository->set_state( 42, OperatorAvailability::BUSY, 1 );

		$this->assertSame( OperatorAvailability::BUSY, $updated->state() );
		$this->assertSame( 1, $updated->updated_by() );

		$found = $this->repository->find_for_operator( 42 );
		$this->assertSame( OperatorAvailability::BUSY, $found->state() );
	}

	public function test_delete_for_operator_removes_the_row(): void {
		$this->repository->set_state( 42, OperatorAvailability::AVAILABLE, 42 );

		$deleted = $this->repository->delete_for_operator( 42 );

		$this->assertTrue( $deleted );
		$this->assertNull( $this->repository->find_for_operator( 42 ) );
	}
}
