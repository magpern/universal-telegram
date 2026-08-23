<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Conversations;

use UniversalTelegram\Conversations\ConversationRepository;
use UniversalTelegram\Conversations\VisitorTokenGenerator;
use UniversalTelegram\Conversations\MessageRepository;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Persistence\SchemaHealth;
use WP_UnitTestCase;

final class MessageRepositoryTest extends WP_UnitTestCase {

	private function repository(): MessageRepository {
		return new MessageRepository( new SchemaHealth(), new CredentialVault() );
	}

	private function conversation_id(): int {
		$conversations = new ConversationRepository( new SchemaHealth(), new CredentialVault(), new VisitorTokenGenerator() );

		return $conversations->create( wp_generate_uuid4(), 'hashed-secret', 1, null )->id();
	}

	public function test_create_stores_ciphertext_and_decrypt_recovers_the_plaintext(): void {
		$repo            = $this->repository();
		$conversation_id = $this->conversation_id();

		$message = $repo->create( $conversation_id, 'visitor', 'Hello, is anyone there?' );

		$this->assertNotNull( $message );
		$this->assertSame( 'visitor', $message->direction() );
		$this->assertNotNull( $message->body_ciphertext() );
		$this->assertStringNotContainsString( 'Hello, is anyone there?', (string) $message->body_ciphertext() );

		$this->assertSame( 'Hello, is anyone there?', $repo->decrypt( $message ) );
	}

	public function test_find_round_trips_a_created_message(): void {
		$repo            = $this->repository();
		$conversation_id = $this->conversation_id();

		$created = $repo->create( $conversation_id, 'operator', 'Sure, how can I help?' );
		$found   = $repo->find( $created->id() );

		$this->assertNotNull( $found );
		$this->assertSame( $created->message_uuid(), $found->message_uuid() );
	}

	public function test_messages_since_returns_only_messages_after_the_cursor_ascending(): void {
		$repo            = $this->repository();
		$conversation_id = $this->conversation_id();

		$first  = $repo->create( $conversation_id, 'visitor', 'first' );
		$second = $repo->create( $conversation_id, 'operator', 'second' );
		$third  = $repo->create( $conversation_id, 'visitor', 'third' );

		$all = $repo->messages_since( $conversation_id, 0 );
		$this->assertCount( 3, $all );
		$this->assertSame( $first->id(), $all[0]->id() );
		$this->assertSame( $third->id(), $all[2]->id() );

		$after_first = $repo->messages_since( $conversation_id, $first->id() );
		$this->assertCount( 2, $after_first );
		$this->assertSame( $second->id(), $after_first[0]->id() );
	}

	public function test_latest_visitor_message_returns_the_most_recent_visitor_direction_row(): void {
		$repo            = $this->repository();
		$conversation_id = $this->conversation_id();

		$repo->create( $conversation_id, 'visitor', 'first visitor message' );
		$repo->create( $conversation_id, 'operator', 'an operator reply' );
		$latest = $repo->create( $conversation_id, 'visitor', 'second visitor message' );

		$found = $repo->latest_visitor_message( $conversation_id );

		$this->assertNotNull( $found );
		$this->assertSame( $latest->id(), $found->id() );
	}

	public function test_latest_visitor_message_returns_null_when_none_exists(): void {
		$repo            = $this->repository();
		$conversation_id = $this->conversation_id();

		$repo->create( $conversation_id, 'operator', 'only an operator message' );

		$this->assertNull( $repo->latest_visitor_message( $conversation_id ) );
	}

	public function test_messages_since_never_returns_another_conversations_messages(): void {
		$repo = $this->repository();

		$own_conversation   = $this->conversation_id();
		$other_conversation = $this->conversation_id();

		$repo->create( $other_conversation, 'visitor', 'not yours' );

		$this->assertSame( array(), $repo->messages_since( $own_conversation, 0 ) );
	}

	public function test_decrypt_returns_null_when_body_ciphertext_is_already_null(): void {
		$repo            = $this->repository();
		$conversation_id = $this->conversation_id();

		$message = $repo->create( $conversation_id, 'visitor', 'to be nulled' );

		global $wpdb;
		$table = $wpdb->prefix . 'universal_telegram_conversation_messages';
		$wpdb->update( $table, array( 'body_ciphertext' => null ), array( 'id' => $message->id() ) );

		$nulled = $repo->find( $message->id() );
		$this->assertNull( $repo->decrypt( $nulled ) );
	}

	public function test_create_persists_and_finds_by_idempotency_key(): void {
		$repo            = $this->repository();
		$conversation_id = $this->conversation_id();

		$created = $repo->create( $conversation_id, 'visitor', 'Hello', 'stored', null, 'idem-key-1' );

		$this->assertSame( 'idem-key-1', $created->idempotency_key() );

		$found = $repo->find_by_idempotency_key( $conversation_id, 'idem-key-1' );
		$this->assertNotNull( $found );
		$this->assertSame( $created->id(), $found->id() );

		$this->assertNull( $repo->find_by_idempotency_key( $conversation_id, 'nonexistent-key' ) );
	}

	public function test_create_without_an_idempotency_key_leaves_it_null(): void {
		$repo            = $this->repository();
		$conversation_id = $this->conversation_id();

		$created = $repo->create( $conversation_id, 'visitor', 'Hello' );

		$this->assertNull( $created->idempotency_key() );
	}

	public function test_create_rejects_a_duplicate_idempotency_key_within_the_same_conversation(): void {
		$repo            = $this->repository();
		$conversation_id = $this->conversation_id();

		$first  = $repo->create( $conversation_id, 'visitor', 'first', 'stored', null, 'dup-key' );
		$second = $repo->create( $conversation_id, 'visitor', 'second', 'stored', null, 'dup-key' );

		$this->assertNotNull( $first );
		$this->assertNull( $second );
	}

	public function test_the_same_idempotency_key_is_allowed_across_different_conversations(): void {
		$repo                = $this->repository();
		$conversation_id_one = $this->conversation_id();
		$conversation_id_two = $this->conversation_id();

		$first  = $repo->create( $conversation_id_one, 'visitor', 'first', 'stored', null, 'shared-key' );
		$second = $repo->create( $conversation_id_two, 'visitor', 'second', 'stored', null, 'shared-key' );

		$this->assertNotNull( $first );
		$this->assertNotNull( $second );
	}

	public function test_create_persists_the_telegram_sender_user_id(): void {
		$repo            = $this->repository();
		$conversation_id = $this->conversation_id();

		$created = $repo->create( $conversation_id, 'operator', 'Hello', 'stored', null, null, 999888777 );

		$this->assertSame( 999888777, $created->telegram_sender_user_id() );
	}

	public function test_create_without_a_telegram_sender_user_id_leaves_it_null(): void {
		$repo            = $this->repository();
		$conversation_id = $this->conversation_id();

		$created = $repo->create( $conversation_id, 'visitor', 'Hello' );

		$this->assertNull( $created->telegram_sender_user_id() );
	}

	public function test_clear_sender_attribution_nulls_the_matching_rows_only(): void {
		$repo                  = $this->repository();
		$conversation_id       = $this->conversation_id();
		$other_conversation_id = $this->conversation_id();

		$matching      = $repo->create( $conversation_id, 'operator', 'reply one', 'stored', null, null, 999888777 );
		$also_matching = $repo->create( $other_conversation_id, 'operator', 'reply two', 'stored', null, null, 999888777 );
		$unrelated     = $repo->create( $conversation_id, 'operator', 'reply three', 'stored', null, null, 111222333 );

		$result = $repo->clear_sender_attribution( 999888777 );

		$this->assertTrue( $result );
		$this->assertNull( $repo->find( $matching->id() )->telegram_sender_user_id() );
		$this->assertNull( $repo->find( $also_matching->id() )->telegram_sender_user_id() );
		$this->assertSame( 111222333, $repo->find( $unrelated->id() )->telegram_sender_user_id() );

		// Message body/ciphertext is untouched — only the join key is cleared.
		$this->assertNotNull( $repo->find( $matching->id() )->body_ciphertext() );
	}
}
