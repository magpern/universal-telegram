<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Conversations;

use UniversalTelegram\Conversations\ConversationRepository;
use UniversalTelegram\Conversations\ConversationStatus;
use UniversalTelegram\Persistence\SchemaHealth;
use WP_UnitTestCase;

final class ConversationRepositoryTest extends WP_UnitTestCase {

	private function repository(): ConversationRepository {
		return new ConversationRepository( new SchemaHealth() );
	}

	public function test_create_and_find_round_trip(): void {
		$repo = $this->repository();

		$created = $repo->create( 'uuid-1', 'hashed-secret', 7, 'sales' );

		$this->assertNotNull( $created );
		$this->assertSame( 'uuid-1', $created->conversation_uuid() );
		$this->assertSame( 'hashed-secret', $created->secret_hash() );
		$this->assertSame( 7, $created->bot_id() );
		$this->assertSame( 'sales', $created->chat_profile() );
		$this->assertSame( ConversationStatus::NEW, $created->status() );
		$this->assertSame( 'none', $created->topic_creation_state() );
		$this->assertNull( $created->destination_id() );

		$found = $repo->find( $created->id() );
		$this->assertNotNull( $found );
		$this->assertSame( $created->conversation_uuid(), $found->conversation_uuid() );
	}

	public function test_find_by_uuid_is_the_sole_lookup_key(): void {
		$repo = $this->repository();

		$created = $repo->create( 'uuid-lookup', 'hashed-secret', 1, null );

		$found = $repo->find_by_uuid( 'uuid-lookup' );
		$this->assertNotNull( $found );
		$this->assertSame( $created->id(), $found->id() );

		$this->assertNull( $repo->find_by_uuid( 'nonexistent-uuid' ) );
	}

	public function test_transition_rejects_a_disallowed_transition(): void {
		$repo = $this->repository();

		$created = $repo->create( 'uuid-transition-1', 'hashed-secret', 1, null );

		$this->assertFalse( $repo->transition( $created->id(), ConversationStatus::NEW, ConversationStatus::ARCHIVED ) );
		$this->assertSame( ConversationStatus::NEW, $repo->find( $created->id() )->status() );
	}

	public function test_transition_applies_an_allowed_transition(): void {
		$repo = $this->repository();

		$created = $repo->create( 'uuid-transition-2', 'hashed-secret', 1, null );

		$this->assertTrue( $repo->transition( $created->id(), ConversationStatus::NEW, ConversationStatus::OPEN ) );
		$this->assertSame( ConversationStatus::OPEN, $repo->find( $created->id() )->status() );
	}

	public function test_transition_is_gated_on_the_recorded_current_status(): void {
		$repo = $this->repository();

		$created = $repo->create( 'uuid-transition-3', 'hashed-secret', 1, null );

		// The row is actually 'new', not 'open' — a stale caller's
		// conditional update must not match any row.
		$this->assertFalse( $repo->transition( $created->id(), ConversationStatus::OPEN, ConversationStatus::WAITING_FOR_VISITOR ) );
		$this->assertSame( ConversationStatus::NEW, $repo->find( $created->id() )->status() );
	}

	public function test_transition_to_resolved_records_resolved_at(): void {
		$repo = $this->repository();

		$created = $repo->create( 'uuid-transition-4', 'hashed-secret', 1, null );
		$repo->transition( $created->id(), ConversationStatus::NEW, ConversationStatus::OPEN );
		$repo->transition( $created->id(), ConversationStatus::OPEN, ConversationStatus::RESOLVED );

		$resolved = $repo->find( $created->id() );
		$this->assertSame( ConversationStatus::RESOLVED, $resolved->status() );
		$this->assertNotNull( $resolved->resolved_at() );
	}

	public function test_revoke_secret_nulls_the_hash(): void {
		$repo = $this->repository();

		$created = $repo->create( 'uuid-revoke', 'hashed-secret', 1, null );
		$this->assertTrue( $repo->revoke_secret( $created->id() ) );

		$found = $repo->find( $created->id() );
		$this->assertNull( $found->secret_hash() );
	}

	public function test_set_destination_persists_the_destination_id(): void {
		$repo = $this->repository();

		$created = $repo->create( 'uuid-destination', 'hashed-secret', 1, null );
		$this->assertTrue( $repo->set_destination( $created->id(), 42 ) );

		$found = $repo->find( $created->id() );
		$this->assertSame( 42, $found->destination_id() );
	}

	public function test_assign_is_never_called_by_m05_request_paths_but_exists_as_a_domain_method(): void {
		$repo = $this->repository();

		$created = $repo->create( 'uuid-assign', 'hashed-secret', 1, null );
		$this->assertTrue( $repo->assign( $created->id(), 99 ) );

		$found = $repo->find( $created->id() );
		$this->assertSame( 99, $found->assigned_operator_id() );
	}

	public function test_create_persists_and_finds_by_start_idempotency_key(): void {
		$repo = $this->repository();

		$created = $repo->create( 'uuid-idem', 'hashed-secret', 1, null, 'idem-key-1' );

		$this->assertSame( 'idem-key-1', $created->start_idempotency_key() );

		$found = $repo->find_by_start_idempotency_key( 'idem-key-1' );
		$this->assertNotNull( $found );
		$this->assertSame( $created->id(), $found->id() );

		$this->assertNull( $repo->find_by_start_idempotency_key( 'nonexistent-key' ) );
	}

	public function test_create_without_a_start_idempotency_key_leaves_it_null(): void {
		$repo = $this->repository();

		$created = $repo->create( 'uuid-no-idem', 'hashed-secret', 1, null );

		$this->assertNull( $created->start_idempotency_key() );
	}

	public function test_create_rejects_a_duplicate_start_idempotency_key(): void {
		$repo = $this->repository();

		$first  = $repo->create( 'uuid-dup-a', 'hashed-secret-a', 1, null, 'dup-key' );
		$second = $repo->create( 'uuid-dup-b', 'hashed-secret-b', 1, null, 'dup-key' );

		$this->assertNotNull( $first );
		$this->assertNull( $second );
	}
}
