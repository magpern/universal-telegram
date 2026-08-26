<?php
/**
 * Unit tests for the legacy conversation export boundary.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Tests\SupportChatAdapter\Migration;

use PHPUnit\Framework\TestCase;
use UniversalTelegram\Conversations\Conversation;
use UniversalTelegram\Conversations\ConversationNote;
use UniversalTelegram\Conversations\ConversationNoteRepository;
use UniversalTelegram\Conversations\ConversationMessage;
use UniversalTelegram\Conversations\ConversationRepository;
use UniversalTelegram\Conversations\MessageRepository;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\SupportChatAdapter\Migration\LegacyExportContextRejectedException;
use UniversalTelegram\SupportChatAdapter\Migration\LegacyExportServiceV1;

/**
 * @covers \UniversalTelegram\SupportChatAdapter\Migration\LegacyExportServiceV1
 */
final class LegacyExportServiceV1Test extends TestCase {

	private function conversation( int $id, ?int $assignee_last_seen_message_id = null ): Conversation {
		return new Conversation(
			$id,
			'uuid-' . $id,
			'secret-hash',
			7,
			42,
			'sales',
			'open',
			5,
			'created',
			123,
			'none',
			'unknown',
			'session-ref-should-never-appear',
			'2026-01-01 00:00:00',
			'2026-01-02 00:00:00',
			null,
			null,
			'start-key-' . $id,
			null,
			'display-name-ciphertext-should-never-appear',
			9,
			$assignee_last_seen_message_id,
			null,
			'none',
			null,
			null
		);
	}

	private function message( int $id, int $conversation_id, ?string $body_ciphertext ): ConversationMessage {
		return new ConversationMessage(
			$id,
			$conversation_id,
			'message-uuid-' . $id,
			'visitor',
			$body_ciphertext,
			'outbound-uuid-should-never-appear',
			999999,
			'stored',
			'2026-01-01 00:00:01',
			null,
			123456789
		);
	}

	private function note( int $id, int $conversation_id ): ConversationNote {
		return new ConversationNote( $id, $conversation_id, 3, 'note-ciphertext', '2026-01-01 00:00:02' );
	}

	/**
	 * The service's only authority check is `defined('WP_CLI') && WP_CLI`
	 * (ADR-0008 §4). Web, Ajax, REST, and cron invocations all share the
	 * identical precondition this service can observe: the constant is not
	 * defined. Each is asserted separately here to make the required
	 * coverage explicit, even though the underlying check is a single line.
	 */
	public function test_rejects_web_context(): void {
		$this->assertRejectsOutsideWpCli();
	}

	public function test_rejects_ajax_context(): void {
		$this->assertRejectsOutsideWpCli();
	}

	public function test_rejects_rest_context(): void {
		$this->assertRejectsOutsideWpCli();
	}

	public function test_rejects_cron_context(): void {
		$this->assertRejectsOutsideWpCli();
	}

	private function assertRejectsOutsideWpCli(): void {
		$conversations = $this->createMock( ConversationRepository::class );
		$conversations->expects( $this->never() )->method( 'after_id' );

		$service = new LegacyExportServiceV1(
			$conversations,
			$this->createMock( MessageRepository::class ),
			$this->createMock( ConversationNoteRepository::class ),
			new SchemaHealth()
		);

		$this->expectException( LegacyExportContextRejectedException::class );
		$service->export_batch( 0, 10 );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_enforces_batch_ceiling_of_100(): void {
		define( 'WP_CLI', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- simulates the real WP-CLI process constant, not a plugin global.

		$conversations = $this->createMock( ConversationRepository::class );
		$conversations->expects( $this->once() )
			->method( 'after_id' )
			->with( 0, 100 )
			->willReturn( array() );

		$service = new LegacyExportServiceV1(
			$conversations,
			$this->createMock( MessageRepository::class ),
			$this->createMock( ConversationNoteRepository::class ),
			new SchemaHealth()
		);

		$result = $service->export_batch( 0, 5000 );

		$this->assertSame( 1, $result['export_schema_version'] );
		$this->assertSame( array(), $result['conversations'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_cursor_is_passed_through_unmodified(): void {
		define( 'WP_CLI', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- simulates the real WP-CLI process constant, not a plugin global.

		$conversations = $this->createMock( ConversationRepository::class );
		$conversations->expects( $this->once() )
			->method( 'after_id' )
			->with( 250, 10 )
			->willReturn( array() );

		$service = new LegacyExportServiceV1(
			$conversations,
			$this->createMock( MessageRepository::class ),
			$this->createMock( ConversationNoteRepository::class ),
			new SchemaHealth()
		);

		$service->export_batch( 250, 10 );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_schema_unavailable_returns_typed_reason_not_partial_data(): void {
		define( 'WP_CLI', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- simulates the real WP-CLI process constant, not a plugin global.

		$schema_health = new SchemaHealth();
		$schema_health->mark_unavailable( \UniversalTelegram\Persistence\MigrationFailureCode::STEP_FAILED );

		$conversations = $this->createMock( ConversationRepository::class );
		$conversations->expects( $this->never() )->method( 'after_id' );

		$service = new LegacyExportServiceV1(
			$conversations,
			$this->createMock( MessageRepository::class ),
			$this->createMock( ConversationNoteRepository::class ),
			$schema_health
		);

		$result = $service->export_batch( 0, 10 );

		$this->assertSame( 'schema_unavailable', $result['error'] );
		$this->assertSame( array(), $result['conversations'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_export_shape_allow_list_and_exclusion(): void {
		define( 'WP_CLI', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- simulates the real WP-CLI process constant, not a plugin global.

		$conversation = $this->conversation( 1, 55 );

		$conversations = $this->createMock( ConversationRepository::class );
		$conversations->method( 'after_id' )->willReturn( array( $conversation ) );

		$messages = $this->createMock( MessageRepository::class );
		$messages->method( 'messages_since' )->willReturn( array() );

		$notes = $this->createMock( ConversationNoteRepository::class );
		$notes->method( 'for_conversation' )->willReturn( array() );

		$service = new LegacyExportServiceV1( $conversations, $messages, $notes, new SchemaHealth() );

		$result = $service->export_batch( 0, 10 );
		$entry  = $result['conversations'][0];

		$allow_listed = array(
			'id',
			'conversation_uuid',
			'bot_id',
			'destination_id',
			'status',
			'assigned_operator_id',
			'owner_user_id',
			'topic_creation_state',
			'telegram_topic_id',
			'topic_lifecycle_state',
			'start_idempotency_key',
			'created_at',
			'updated_at',
			'resolved_at',
			'expires_at',
			'assignee_last_seen_message_id',
			'messages',
			'notes',
		);

		$this->assertSame( $allow_listed, array_keys( $entry ) );

		$excluded_values = array(
			'secret-hash',
			'sales',
			'session-ref-should-never-appear',
			'display-name-ciphertext-should-never-appear',
		);
		foreach ( $excluded_values as $excluded_value ) {
			$this->assertFalse( in_array( $excluded_value, $entry, true ), 'Unexpected excluded value present: ' . $excluded_value );
		}

		$this->assertSame( 55, $entry['assignee_last_seen_message_id'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_messages_and_notes_are_exported_in_repository_order(): void {
		define( 'WP_CLI', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- simulates the real WP-CLI process constant, not a plugin global.

		$conversation = $this->conversation( 1 );

		$conversations = $this->createMock( ConversationRepository::class );
		$conversations->method( 'after_id' )->willReturn( array( $conversation ) );

		$message_a = $this->message( 10, 1, 'cipher-a' );
		$message_b = $this->message( 11, 1, 'cipher-b' );

		$messages = $this->createMock( MessageRepository::class );
		$messages->method( 'messages_since' )->willReturn( array( $message_a, $message_b ) );
		$messages->method( 'decrypt' )->willReturnMap(
			array(
				array( $message_a, 'plaintext-a' ),
				array( $message_b, 'plaintext-b' ),
			)
		);

		$note_a = $this->note( 20, 1 );
		$note_b = $this->note( 21, 1 );

		$notes = $this->createMock( ConversationNoteRepository::class );
		$notes->method( 'for_conversation' )->willReturn( array( $note_a, $note_b ) );
		$notes->method( 'decrypt' )->willReturnMap(
			array(
				array( $note_a, 'note-plaintext-a' ),
				array( $note_b, 'note-plaintext-b' ),
			)
		);

		$service = new LegacyExportServiceV1( $conversations, $messages, $notes, new SchemaHealth() );

		$entry = $service->export_batch( 0, 10 )['conversations'][0];

		$this->assertSame( array( 'plaintext-a', 'plaintext-b' ), array_column( $entry['messages'], 'body' ) );
		$this->assertSame( array( 10, 11 ), array_column( $entry['messages'], 'id' ) );
		$this->assertSame( array( 'note-plaintext-a', 'note-plaintext-b' ), array_column( $entry['notes'], 'body' ) );
		$this->assertSame( array( 20, 21 ), array_column( $entry['notes'], 'id' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_message_decrypt_failure_yields_typed_error_without_aborting_batch(): void {
		define( 'WP_CLI', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- simulates the real WP-CLI process constant, not a plugin global.

		$ok_conversation      = $this->conversation( 1 );
		$failing_conversation = $this->conversation( 2 );

		$conversations = $this->createMock( ConversationRepository::class );
		$conversations->method( 'after_id' )->willReturn( array( $failing_conversation, $ok_conversation ) );

		$failing_message = $this->message( 30, 2, 'cipher-that-fails' );
		$ok_message      = $this->message( 31, 1, 'cipher-that-works' );

		$messages = $this->createMock( MessageRepository::class );
		$messages->method( 'messages_since' )->willReturnMap(
			array(
				array( 2, 0, array( $failing_message ) ),
				array( 1, 0, array( $ok_message ) ),
			)
		);
		$messages->method( 'decrypt' )->willReturnMap(
			array(
				array( $failing_message, null ),
				array( $ok_message, 'plaintext-ok' ),
			)
		);

		$notes = $this->createMock( ConversationNoteRepository::class );
		$notes->method( 'for_conversation' )->willReturn( array() );

		$service = new LegacyExportServiceV1( $conversations, $messages, $notes, new SchemaHealth() );

		$result = $service->export_batch( 0, 10 );

		$expected_error_entry = array(
			'id'    => 2,
			'error' => 'decrypt_failed',
		);
		$this->assertSame( $expected_error_entry, $result['conversations'][0] );
		$this->assertSame( 1, $result['conversations'][1]['id'] );
		$this->assertSame( 'plaintext-ok', $result['conversations'][1]['messages'][0]['body'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_null_ciphertext_message_body_is_not_a_decrypt_failure(): void {
		define( 'WP_CLI', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- simulates the real WP-CLI process constant, not a plugin global.

		$conversation = $this->conversation( 1 );

		$conversations = $this->createMock( ConversationRepository::class );
		$conversations->method( 'after_id' )->willReturn( array( $conversation ) );

		$retention_nulled_message = $this->message( 40, 1, null );

		$messages = $this->createMock( MessageRepository::class );
		$messages->method( 'messages_since' )->willReturn( array( $retention_nulled_message ) );
		$messages->expects( $this->never() )->method( 'decrypt' );

		$notes = $this->createMock( ConversationNoteRepository::class );
		$notes->method( 'for_conversation' )->willReturn( array() );

		$service = new LegacyExportServiceV1( $conversations, $messages, $notes, new SchemaHealth() );

		$entry = $service->export_batch( 0, 10 )['conversations'][0];

		$this->assertArrayNotHasKey( 'error', $entry );
		$this->assertNull( $entry['messages'][0]['body'] );
	}

	/**
	 * This service calls only the documented read methods on each
	 * collaborator — it must never mutate legacy source records. Each
	 * collaborator's representative write methods are explicitly asserted
	 * never called.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_only_read_methods_are_invoked_on_repositories(): void {
		define( 'WP_CLI', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- simulates the real WP-CLI process constant, not a plugin global.

		$conversation = $this->conversation( 1 );

		$conversations = $this->createMock( ConversationRepository::class );
		$conversations->method( 'after_id' )->willReturn( array( $conversation ) );
		$conversations->expects( $this->never() )->method( 'create' );
		$conversations->expects( $this->never() )->method( 'transition' );
		$conversations->expects( $this->never() )->method( 'assign' );
		$conversations->expects( $this->never() )->method( 'delete' );
		$conversations->expects( $this->never() )->method( 'mark_seen' );

		$messages = $this->createMock( MessageRepository::class );
		$messages->method( 'messages_since' )->willReturn( array() );
		$messages->expects( $this->never() )->method( 'create' );
		$messages->expects( $this->never() )->method( 'mark_delivery_sent' );
		$messages->expects( $this->never() )->method( 'mark_delivery_failed' );
		$messages->expects( $this->never() )->method( 'delete_for_conversation' );
		$messages->expects( $this->never() )->method( 'null_bodies_for_conversation' );

		$notes = $this->createMock( ConversationNoteRepository::class );
		$notes->method( 'for_conversation' )->willReturn( array() );
		$notes->expects( $this->never() )->method( 'create' );
		$notes->expects( $this->never() )->method( 'delete_for_conversation' );
		$notes->expects( $this->never() )->method( 'anonymize_author' );

		$service = new LegacyExportServiceV1( $conversations, $messages, $notes, new SchemaHealth() );

		$service->export_batch( 0, 10 );
	}
}
