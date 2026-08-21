<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Conversations;

use UniversalTelegram\Conversations\ConversationRepository;
use UniversalTelegram\Conversations\MessageRepository;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Persistence\SchemaHealth;
use WP_UnitTestCase;

final class MessageRepositoryTest extends WP_UnitTestCase {

	private function repository(): MessageRepository {
		return new MessageRepository( new SchemaHealth(), new CredentialVault() );
	}

	private function conversation_id(): int {
		$conversations = new ConversationRepository( new SchemaHealth() );

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
}
