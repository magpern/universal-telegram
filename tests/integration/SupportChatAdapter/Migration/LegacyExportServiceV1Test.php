<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\SupportChatAdapter\Migration;

use UniversalTelegram\Conversations\ConversationNoteRepository;
use UniversalTelegram\Conversations\ConversationRepository;
use UniversalTelegram\Conversations\MessageRepository;
use UniversalTelegram\Conversations\VisitorTokenGenerator;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\SupportChatAdapter\Migration\LegacyExportServiceV1;
use WP_UnitTestCase;

if ( ! defined( 'WP_CLI' ) ) {
	// This integration suite exercises LegacyExportServiceV1 exactly as
	// Support Chat's own future WP-CLI migration command would: in-process,
	// from within an authorized WP-CLI process (Support Chat ADR-0008 §4).
	// No other integration test in this repository relies on WP_CLI being
	// undefined.
	define( 'WP_CLI', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- simulates the real WP-CLI process constant, not a plugin global.
}

final class LegacyExportServiceV1Test extends WP_UnitTestCase {

	private function conversations(): ConversationRepository {
		return new ConversationRepository( new SchemaHealth(), new CredentialVault(), new VisitorTokenGenerator() );
	}

	private function messages(): MessageRepository {
		return new MessageRepository( new SchemaHealth(), new CredentialVault() );
	}

	private function notes(): ConversationNoteRepository {
		return new ConversationNoteRepository( new SchemaHealth(), new CredentialVault() );
	}

	private function service(): LegacyExportServiceV1 {
		return new LegacyExportServiceV1( $this->conversations(), $this->messages(), $this->notes(), new SchemaHealth() );
	}

	public function test_export_batch_returns_decrypted_plaintext_via_the_existing_vault_path(): void {
		$conversations = $this->conversations();
		$conversation  = $conversations->create( wp_generate_uuid4(), 'hashed-secret', 3, 'sales' );

		$this->messages()->create( $conversation->id(), 'visitor', 'Is my order shipped yet?' );
		$this->messages()->create( $conversation->id(), 'operator', 'Yes, shipped this morning.' );
		$this->notes()->create( $conversation->id(), 7, 'Called back, confirmed shipment.' );

		$result = $this->service()->export_batch( 0, 10 );

		$this->assertSame( 1, $result['export_schema_version'] );
		$this->assertArrayNotHasKey( 'error', $result );

		$entries = array_filter( $result['conversations'], static fn( array $entry ): bool => $entry['id'] === $conversation->id() );
		$entry   = array_values( $entries )[0];

		$this->assertSame( $conversation->conversation_uuid(), $entry['conversation_uuid'] );
		$this->assertSame(
			array( 'Is my order shipped yet?', 'Yes, shipped this morning.' ),
			array_column( $entry['messages'], 'body' )
		);
		$this->assertSame( array( 'Called back, confirmed shipment.' ), array_column( $entry['notes'], 'body' ) );
	}

	public function test_export_batch_never_emits_redacted_fields(): void {
		$conversations = $this->conversations();
		$conversation  = $conversations->create( wp_generate_uuid4(), 'hashed-secret', 3, 'sales-profile-must-not-appear' );

		$result = $this->service()->export_batch( 0, 10 );

		$entries = array_filter( $result['conversations'], static fn( array $entry ): bool => $entry['id'] === $conversation->id() );
		$entry   = array_values( $entries )[0];

		$redacted = array(
			'secret_hash',
			'chat_profile',
			'session_ref',
			'consent_state',
			'ai_participation_state',
			'ai_ack_policy_version',
			'display_name_ciphertext',
			'topic_claim_expires_at',
			'topic_lifecycle_code',
			'topic_delete_claim_expires_at',
		);

		foreach ( $redacted as $field ) {
			$this->assertArrayNotHasKey( $field, $entry );
		}

		$this->assertFalse( in_array( 'sales-profile-must-not-appear', $entry, true ) );

		foreach ( $entry['messages'] as $message_entry ) {
			$this->assertArrayNotHasKey( 'outbound_message_uuid', $message_entry );
			$this->assertArrayNotHasKey( 'telegram_message_id', $message_entry );
			$this->assertArrayNotHasKey( 'telegram_sender_user_id', $message_entry );
			$this->assertArrayNotHasKey( 'delivery_state', $message_entry );
		}
	}

	public function test_cursor_repeatability_picks_up_only_newly_created_rows(): void {
		$conversations = $this->conversations();
		$first         = $conversations->create( wp_generate_uuid4(), 'hashed-secret', 3, null );

		$first_pass = $this->service()->export_batch( 0, 100 );
		$max_id     = max( array_column( $first_pass['conversations'], 'id' ) );

		$second = $conversations->create( wp_generate_uuid4(), 'hashed-secret', 3, null );

		$second_pass = $this->service()->export_batch( $max_id, 100 );

		$this->assertCount( 1, $second_pass['conversations'] );
		$this->assertSame( $second->id(), $second_pass['conversations'][0]['id'] );
	}

	public function test_export_batch_does_not_mutate_legacy_source_records(): void {
		$conversations = $this->conversations();
		$message_repo  = $this->messages();
		$conversation  = $conversations->create( wp_generate_uuid4(), 'hashed-secret', 3, null );
		$message_repo->create( $conversation->id(), 'visitor', 'Unchanged after export.' );

		$before = $conversations->find( $conversation->id() );

		$this->service()->export_batch( 0, 10 );

		$after = $conversations->find( $conversation->id() );

		$this->assertSame( $before->status(), $after->status() );
		$this->assertSame( $before->updated_at(), $after->updated_at() );

		$stored_message = $message_repo->messages_since( $conversation->id(), 0 )[0];
		$this->assertSame( 'Unchanged after export.', $message_repo->decrypt( $stored_message ) );
	}

	public function test_export_batch_marks_a_retention_nulled_message_body_as_null_not_a_decrypt_failure(): void {
		$conversations = $this->conversations();
		$conversation  = $conversations->create( wp_generate_uuid4(), 'hashed-secret', 3, null );
		$this->messages()->create( $conversation->id(), 'visitor', 'Will be retention-nulled.' );
		$this->messages()->null_bodies_for_conversation( $conversation->id() );

		$result = $this->service()->export_batch( 0, 10 );

		$entries = array_filter( $result['conversations'], static fn( array $entry ): bool => $entry['id'] === $conversation->id() );
		$entry   = array_values( $entries )[0];

		$this->assertArrayNotHasKey( 'error', $entry );
		$this->assertNull( $entry['messages'][0]['body'] );
	}
}
