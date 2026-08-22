<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Conversations;

use UniversalTelegram\Conversations\ConversationRepository;
use UniversalTelegram\Conversations\VisitorTokenGenerator;
use UniversalTelegram\Conversations\ConversationStatus;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Persistence\SchemaHealth;
use WP_UnitTestCase;

final class ConversationRepositoryTest extends WP_UnitTestCase {

	private function repository(): ConversationRepository {
		return new ConversationRepository( new SchemaHealth(), new CredentialVault(), new VisitorTokenGenerator() );
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

	public function test_try_begin_topic_creation_concurrent_race_exactly_one_wins(): void {
		$repo         = $this->repository();
		$conversation = $repo->create( 'uuid-claim-race', 'hash', 1, null );

		$first  = $repo->try_begin_topic_creation( $conversation->id() );
		$second = $repo->try_begin_topic_creation( $conversation->id() );

		$this->assertNotNull( $first );
		$this->assertNull( $second );
		$this->assertSame( 'pending', $repo->find( $conversation->id() )->topic_creation_state() );
	}

	public function test_try_begin_topic_creation_reclaims_only_after_the_lease_expires(): void {
		$repo         = $this->repository();
		$conversation = $repo->create( 'uuid-claim-expiry', 'hash', 1, null );

		$this->assertNotNull( $repo->try_begin_topic_creation( $conversation->id(), 15 ) );

		// Still within the lease: no reclaim.
		$this->assertNull( $repo->try_begin_topic_creation( $conversation->id(), 15 ) );

		// Simulate an already-expired lease directly, as a crashed prior
		// claimant would leave it.
		global $wpdb;
		$table = $wpdb->prefix . \UniversalTelegram\Persistence\Migrator::CONVERSATIONS_TABLE;
		$wpdb->update(
			$table,
			array( 'topic_claim_expires_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ),
			array( 'id' => $conversation->id() )
		);

		$reclaimed = $repo->try_begin_topic_creation( $conversation->id(), 15 );
		$this->assertNotNull( $reclaimed );
	}

	public function test_try_begin_topic_creation_never_reclaims_a_terminal_state(): void {
		$repo         = $this->repository();
		$conversation = $repo->create( 'uuid-claim-terminal', 'hash', 1, null );

		$repo->try_begin_topic_creation( $conversation->id() );
		$repo->mark_topic_failed( $conversation->id() );

		// Even with an expired lease left behind, 'failed' is never
		// re-enterable.
		global $wpdb;
		$table = $wpdb->prefix . \UniversalTelegram\Persistence\Migrator::CONVERSATIONS_TABLE;
		$wpdb->update(
			$table,
			array(
				'topic_creation_state'   => 'failed',
				'topic_claim_expires_at' => gmdate( 'Y-m-d H:i:s', time() - 100 ),
			),
			array( 'id' => $conversation->id() )
		);

		$this->assertNull( $repo->try_begin_topic_creation( $conversation->id() ) );
	}

	public function test_display_name_required_is_true_until_a_name_is_stored(): void {
		$repo         = $this->repository();
		$conversation = $repo->create( 'uuid-name-required', 'hash', 1, null );

		$this->assertTrue( $conversation->display_name_required() );
		$this->assertNull( $conversation->display_name_ciphertext() );

		$this->assertTrue( $repo->store_display_name( $conversation, 'Alice' ) );

		$stored = $repo->find( $conversation->id() );
		$this->assertFalse( $stored->display_name_required() );
		$this->assertNotNull( $stored->display_name_ciphertext() );
		$this->assertNotSame( 'Alice', $stored->display_name_ciphertext() );
	}

	public function test_store_display_name_is_write_once(): void {
		$repo         = $this->repository();
		$conversation = $repo->create( 'uuid-name-write-once', 'hash', 1, null );

		$this->assertTrue( $repo->store_display_name( $conversation, 'Alice' ) );
		$first_ciphertext = $repo->find( $conversation->id() )->display_name_ciphertext();

		// A second call, even against a freshly-fetched (now-named)
		// Conversation object, must be a no-op: display_name_required()
		// already reports false, so the write-once guard short-circuits.
		$named_again = $repo->find( $conversation->id() );
		$this->assertTrue( $repo->store_display_name( $named_again, 'Bob' ) );

		$final = $repo->find( $conversation->id() );
		$this->assertSame( $first_ciphertext, $final->display_name_ciphertext() );
	}

	public function test_decrypt_display_name_round_trips(): void {
		$repo         = $this->repository();
		$conversation = $repo->create( 'uuid-name-decrypt', 'hash', 1, null );

		$repo->store_display_name( $conversation, 'Cee Séamus' );
		$stored = $repo->find( $conversation->id() );

		$this->assertSame( 'Cee Séamus', $repo->decrypt_display_name( $stored ) );
	}

	public function test_decrypt_display_name_returns_null_when_none_is_stored(): void {
		$repo         = $this->repository();
		$conversation = $repo->create( 'uuid-name-none', 'hash', 1, null );

		$this->assertNull( $repo->decrypt_display_name( $conversation ) );
	}

	public function test_inactive_open_conversations_matches_only_eligible_stale_statuses(): void {
		$repo = $this->repository();

		$stale_open = $repo->create( 'uuid-inactive-open', 'hash', 1, null );
		$repo->transition( $stale_open->id(), ConversationStatus::NEW, ConversationStatus::OPEN );

		$fresh_open = $repo->create( 'uuid-fresh-open', 'hash', 1, null );
		$repo->transition( $fresh_open->id(), ConversationStatus::NEW, ConversationStatus::OPEN );

		$stale_new = $repo->create( 'uuid-inactive-new', 'hash', 1, null );

		global $wpdb;
		$table  = $wpdb->prefix . \UniversalTelegram\Persistence\Migrator::CONVERSATIONS_TABLE;
		$old_ts = gmdate( 'Y-m-d H:i:s', time() - ( 31 * DAY_IN_SECONDS ) );
		$wpdb->update( $table, array( 'updated_at' => $old_ts ), array( 'id' => $stale_open->id() ) );
		$wpdb->update( $table, array( 'updated_at' => $old_ts ), array( 'id' => $stale_new->id() ) );

		$inactive = $repo->inactive_open_conversations( 30 );
		$ids      = array_map( static fn( $c ) => $c->id(), $inactive );

		$this->assertContains( $stale_open->id(), $ids );
		$this->assertNotContains( $fresh_open->id(), $ids );
		$this->assertContains( $stale_new->id(), $ids, 'NEW must be matched too (M06.3.1, ADR-0025) since it now occupies the owner_active_slot index and must be freeable.' );
	}

	public function test_inactive_open_conversations_never_matches_resolved_or_archived(): void {
		$repo         = $this->repository();
		$conversation = $repo->create( 'uuid-inactive-resolved', 'hash', 1, null );
		$repo->transition( $conversation->id(), ConversationStatus::NEW, ConversationStatus::OPEN );
		$repo->transition( $conversation->id(), ConversationStatus::OPEN, ConversationStatus::RESOLVED );

		global $wpdb;
		$table = $wpdb->prefix . \UniversalTelegram\Persistence\Migrator::CONVERSATIONS_TABLE;
		$wpdb->update(
			$table,
			array( 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - ( 31 * DAY_IN_SECONDS ) ) ),
			array( 'id' => $conversation->id() )
		);

		$inactive = $repo->inactive_open_conversations( 30 );
		$ids      = array_map( static fn( $c ) => $c->id(), $inactive );

		$this->assertNotContains( $conversation->id(), $ids );
	}

	public function test_destination_ids_for_bot_returns_only_this_bots_distinct_destination_ids(): void {
		$repo = $this->repository();

		$conversation_a = $repo->create( 'uuid-dest-a', 'hash', 1, null );
		$repo->set_destination( $conversation_a->id(), 501 );

		$conversation_b = $repo->create( 'uuid-dest-b', 'hash', 1, null );
		$repo->set_destination( $conversation_b->id(), 502 );

		$conversation_other_bot = $repo->create( 'uuid-dest-other-bot', 'hash', 2, null );
		$repo->set_destination( $conversation_other_bot->id(), 999 );

		$conversation_no_destination = $repo->create( 'uuid-dest-none', 'hash', 1, null );

		$ids = $repo->destination_ids_for_bot( 1 );

		$this->assertContains( 501, $ids );
		$this->assertContains( 502, $ids );
		$this->assertNotContains( 999, $ids );
	}

	public function test_create_persists_owner_and_display_name_atomically(): void {
		$repo = $this->repository();

		$created = $repo->create( 'uuid-owned-1', 'hash', 1, null, 'idem-owned-1', 99, 'Alice' );

		$this->assertNotNull( $created );
		$this->assertSame( 99, $created->owner_user_id() );
		$this->assertFalse( $created->display_name_required() );
		$this->assertSame( 'Alice', $repo->decrypt_display_name( $created ) );
	}

	public function test_create_or_resume_owned_creates_a_fresh_row_when_none_is_active(): void {
		$repo = $this->repository();

		$result = $repo->create_or_resume_owned( 'uuid-owned-2', 'hash', 1, null, 'idem-owned-2', 101, 'Bob' );

		$this->assertNotNull( $result );
		$this->assertFalse( $result['resumed'] );
		$this->assertNull( $result['secret'] );
		$this->assertSame( 101, $result['conversation']->owner_user_id() );
	}

	public function test_create_or_resume_owned_resumes_and_rotates_the_secret_on_collision(): void {
		$repo = $this->repository();

		$first = $repo->create_or_resume_owned( 'uuid-owned-3a', 'hash-a', 1, null, 'idem-owned-3a', 102, 'Carol' );
		$this->assertNotNull( $first );

		$second = $repo->create_or_resume_owned( 'uuid-owned-3b', 'hash-b', 1, null, 'idem-owned-3b', 102, 'Carol' );

		$this->assertNotNull( $second );
		$this->assertTrue( $second['resumed'] );
		$this->assertSame( $first['conversation']->id(), $second['conversation']->id() );
		$this->assertNotNull( $second['secret'] );

		$refreshed = $repo->find( $first['conversation']->id() );
		$this->assertNotNull( $refreshed );
		$this->assertTrue( ( new VisitorTokenGenerator() )->verify( $second['secret'], (string) $refreshed->secret_hash() ) );
	}

	public function test_create_or_resume_owned_never_creates_a_second_row_on_collision(): void {
		$repo = $this->repository();

		$repo->create_or_resume_owned( 'uuid-owned-4a', 'hash-a', 1, null, 'idem-owned-4a', 103, 'Dana' );
		$repo->create_or_resume_owned( 'uuid-owned-4b', 'hash-b', 1, null, 'idem-owned-4b', 103, 'Dana' );

		$this->assertNull( $repo->find_by_uuid( 'uuid-owned-4b' ), 'The collision must never create a second row.' );
	}

	public function test_find_active_for_owner_ignores_resolved_and_archived_rows(): void {
		$repo         = $this->repository();
		$conversation = $repo->create( 'uuid-owned-5', 'hash', 1, null, null, 104, 'Erin' );
		$repo->transition( $conversation->id(), ConversationStatus::NEW, ConversationStatus::OPEN );
		$repo->transition( $conversation->id(), ConversationStatus::OPEN, ConversationStatus::RESOLVED );

		$this->assertNull( $repo->find_active_for_owner( 104, 1 ) );
	}

	public function test_find_active_for_owner_never_crosses_bots(): void {
		$repo = $this->repository();
		$repo->create( 'uuid-owned-6', 'hash', 1, null, null, 105, 'Faye' );

		$this->assertNull( $repo->find_active_for_owner( 105, 2 ) );
	}

	public function test_rotate_secret_invalidates_the_previous_secret(): void {
		$repo         = $this->repository();
		$tokens       = new VisitorTokenGenerator();
		$conversation = $repo->create( 'uuid-owned-7', $tokens->hash( 'old-secret' ), 1, null, null, 106, 'Gwen' );

		$fresh = $repo->rotate_secret( $conversation->id() );

		$this->assertNotNull( $fresh );
		$refreshed = $repo->find( $conversation->id() );
		$this->assertFalse( $tokens->verify( 'old-secret', (string) $refreshed->secret_hash() ) );
		$this->assertTrue( $tokens->verify( $fresh, (string) $refreshed->secret_hash() ) );
	}

	public function test_release_owner_conversations_clears_ownership_and_revokes_the_secret(): void {
		$repo         = $this->repository();
		$conversation = $repo->create( 'uuid-owned-8', 'hash', 1, null, null, 107, 'Hana' );

		$repo->release_owner_conversations( 107 );

		$refreshed = $repo->find( $conversation->id() );
		$this->assertNull( $refreshed->owner_user_id() );
		$this->assertNull( $refreshed->secret_hash() );
	}

	public function test_release_owner_conversations_never_touches_another_owners_row(): void {
		$repo = $this->repository();
		$repo->create( 'uuid-owned-9', 'hash', 1, null, null, 108, 'Ida' );
		$other = $repo->create( 'uuid-owned-10', 'hash', 1, null, null, 109, 'Jae' );

		$repo->release_owner_conversations( 108 );

		$refreshed = $repo->find( $other->id() );
		$this->assertSame( 109, $refreshed->owner_user_id() );
	}
}
