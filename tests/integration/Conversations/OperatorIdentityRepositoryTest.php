<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Conversations;

use UniversalTelegram\Conversations\OperatorIdentityRepository;
use UniversalTelegram\Persistence\SchemaHealth;
use WP_UnitTestCase;

final class OperatorIdentityRepositoryTest extends WP_UnitTestCase {

	private OperatorIdentityRepository $repository;

	protected function setUp(): void {
		parent::setUp();

		$this->repository = new OperatorIdentityRepository( new SchemaHealth() );
	}

	public function test_create_and_find_round_trips(): void {
		$mapping = $this->repository->create( 42, 999888777, 'opuser', 1 );

		$this->assertNotNull( $mapping );
		$this->assertSame( 42, $mapping->wp_user_id() );
		$this->assertSame( 999888777, $mapping->telegram_user_id() );
		$this->assertSame( 'opuser', $mapping->telegram_username() );

		$found = $this->repository->find( $mapping->id() );
		$this->assertNotNull( $found );
		$this->assertSame( 42, $found->wp_user_id() );
	}

	public function test_find_by_wp_user_id(): void {
		$this->repository->create( 42, 999888777, null, 1 );

		$found = $this->repository->find_by_wp_user_id( 42 );
		$this->assertNotNull( $found );
		$this->assertSame( 999888777, $found->telegram_user_id() );

		$this->assertNull( $this->repository->find_by_wp_user_id( 9999 ) );
	}

	public function test_find_by_telegram_user_id(): void {
		$this->repository->create( 42, 999888777, null, 1 );

		$found = $this->repository->find_by_telegram_user_id( 999888777 );
		$this->assertNotNull( $found );
		$this->assertSame( 42, $found->wp_user_id() );

		$this->assertNull( $this->repository->find_by_telegram_user_id( 1 ) );
	}

	public function test_wp_user_id_is_unique(): void {
		$this->repository->create( 42, 111, null, 1 );
		$second = $this->repository->create( 42, 222, null, 1 );

		$this->assertNull( $second );
	}

	public function test_telegram_user_id_is_unique(): void {
		$this->repository->create( 42, 111, null, 1 );
		$second = $this->repository->create( 43, 111, null, 1 );

		$this->assertNull( $second );
	}

	public function test_delete_for_wp_user_removes_the_mapping(): void {
		$this->repository->create( 42, 999888777, null, 1 );

		$deleted = $this->repository->delete_for_wp_user( 42 );

		$this->assertTrue( $deleted );
		$this->assertNull( $this->repository->find_by_wp_user_id( 42 ) );
	}
}
