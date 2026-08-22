<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Core;

use UniversalTelegram\Audit\AuditLogRepository;
use UniversalTelegram\Conversations\OperatorAvailability;
use UniversalTelegram\Core\Plugin;
use UniversalTelegram\Persistence\MigrationLock;
use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Persistence\SchemaHealth;
use WP_UnitTestCase;

/**
 * M07 (ADR-0026 decision 12): deleting a WordPress user who was mapped as
 * an operator must, in addition to the pre-existing visitor-owner cleanup
 * (PluginAccountDeletionTest), clear that operator's Telegram-sender
 * attribution on existing messages, anonymize their note authorship,
 * unassign their conversations and reset assignee_last_seen_message_id,
 * delete their availability/identity rows, and record exactly one
 * empty-context system audit entry — never their wp_user_id, Telegram id,
 * or username. Exercises the real, already-booted composition root's
 * `deleted_user` wiring, not the repositories in isolation.
 */
final class OperatorAccountDeletionCleanupTest extends WP_UnitTestCase {

	public function test_full_deleted_user_cleanup_sequence(): void {
		global $wpdb;

		( new Migrator( new MigrationLock() ) )->maybe_migrate();
		$wpdb->db_connect( true );

		$plugin        = Plugin::instance();
		$conversations = $plugin->conversation_repository();
		$messages      = $plugin->message_repository();
		$notes         = $plugin->conversation_note_repository();
		$identities    = $plugin->operator_identity_repository();
		$availability  = $plugin->operator_availability_repository();

		$this->assertNotNull( $conversations );
		$this->assertNotNull( $messages );
		$this->assertNotNull( $notes );
		$this->assertNotNull( $identities );
		$this->assertNotNull( $availability );

		$operator_wp_user_id  = self::factory()->user->create();
		$telegram_user_id     = 999444777;

		$identities->create( $operator_wp_user_id, $telegram_user_id, 'opuser', 1 );
		$availability->set_state( $operator_wp_user_id, OperatorAvailability::AVAILABLE, $operator_wp_user_id );

		$conversation = $conversations->create( 'uuid-operator-deletion', 'hash', 1, null );
		$conversations->assign( $conversation->id(), $operator_wp_user_id );

		$message = $messages->create( $conversation->id(), 'operator', 'On it, checking now.', 'stored', null, null, $telegram_user_id );
		$note    = $notes->create( $conversation->id(), $operator_wp_user_id, 'Customer called back twice.' );

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.HookCommentWrongStyle, WooCommerce.Commenting.CommentHooks.MissingHookComment, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		do_action( 'deleted_user', $operator_wp_user_id, null, null, null );

		// (a) message attribution cleared, content untouched.
		$reloaded_message = $messages->find( $message->id() );
		$this->assertNull( $reloaded_message->telegram_sender_user_id() );
		$this->assertNotNull( $reloaded_message->body_ciphertext() );

		// (b) note authorship anonymized, content untouched.
		$reloaded_note = $notes->find( $note->id() );
		$this->assertNull( $reloaded_note->operator_user_id() );
		$this->assertSame( 'Customer called back twice.', $notes->decrypt( $reloaded_note ) );

		// (c) assignment and unread state cleared.
		$reloaded_conversation = $conversations->find( $conversation->id() );
		$this->assertNull( $reloaded_conversation->assigned_operator_id() );
		$this->assertNull( $reloaded_conversation->assignee_last_seen_message_id() );

		// (d) availability row deleted.
		$this->assertNull( $availability->find_for_operator( $operator_wp_user_id ) );

		// (e) identity mapping row deleted.
		$this->assertNull( $identities->find_by_wp_user_id( $operator_wp_user_id ) );

		// (f) exactly one empty-context system audit entry, no personal values.
		$audit_log = new AuditLogRepository( new SchemaHealth() );
		$entries   = array_values(
			array_filter(
				$audit_log->recent( 50 ),
				static function ( array $entry ): bool {
					return 'conversation.operator_identity.account_deleted_cleanup' === $entry['action'];
				}
			)
		);

		$this->assertCount( 1, $entries );
		$this->assertSame( 'system', $entries[0]['actor_type'] );
		$this->assertNull( $entries[0]['actor_id'] );

		$context = json_decode( (string) $entries[0]['context'], true );
		$this->assertSame( array(), $context );
	}
}
