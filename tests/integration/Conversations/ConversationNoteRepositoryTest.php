<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Conversations;

use UniversalTelegram\Conversations\ConversationNoteRepository;
use UniversalTelegram\Conversations\ConversationRepository;
use UniversalTelegram\Conversations\VisitorTokenGenerator;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Persistence\SchemaHealth;
use WP_UnitTestCase;

final class ConversationNoteRepositoryTest extends WP_UnitTestCase {

	private function repository(): ConversationNoteRepository {
		return new ConversationNoteRepository( new SchemaHealth(), new CredentialVault() );
	}

	private function conversation_id(): int {
		$conversations = new ConversationRepository( new SchemaHealth(), new CredentialVault(), new VisitorTokenGenerator() );

		return $conversations->create( wp_generate_uuid4(), 'hash', 1, null )->id();
	}

	public function test_create_and_decrypt_round_trips(): void {
		$repo            = $this->repository();
		$conversation_id = $this->conversation_id();

		$note = $repo->create( $conversation_id, 7, 'Called back, waiting on shipment.' );

		$this->assertNotNull( $note );
		$this->assertSame( 7, $note->operator_user_id() );
		$this->assertSame( 'Called back, waiting on shipment.', $repo->decrypt( $note ) );
	}

	public function test_for_conversation_returns_notes_oldest_first(): void {
		$repo            = $this->repository();
		$conversation_id = $this->conversation_id();

		$first  = $repo->create( $conversation_id, 7, 'first note' );
		$second = $repo->create( $conversation_id, 7, 'second note' );

		$notes = $repo->for_conversation( $conversation_id );

		$this->assertCount( 2, $notes );
		$this->assertSame( $first->id(), $notes[0]->id() );
		$this->assertSame( $second->id(), $notes[1]->id() );
	}

	public function test_anonymize_author_nulls_author_but_preserves_content(): void {
		$repo            = $this->repository();
		$conversation_id = $this->conversation_id();

		$note = $repo->create( $conversation_id, 7, 'Sensitive customer detail.' );

		$result = $repo->anonymize_author( 7 );

		$this->assertTrue( $result );
		$reloaded = $repo->find( $note->id() );
		$this->assertNull( $reloaded->operator_user_id() );
		$this->assertSame( 'Sensitive customer detail.', $repo->decrypt( $reloaded ) );
	}

	public function test_anonymize_author_never_touches_another_operators_notes(): void {
		$repo            = $this->repository();
		$conversation_id = $this->conversation_id();

		$note = $repo->create( $conversation_id, 8, 'Different operator note.' );

		$repo->anonymize_author( 7 );

		$reloaded = $repo->find( $note->id() );
		$this->assertSame( 8, $reloaded->operator_user_id() );
	}
}
