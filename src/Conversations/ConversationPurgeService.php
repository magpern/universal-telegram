<?php
/**
 * Shared conversation purge sequence.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Conversations;

use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Telegram\Configuration\DestinationRepository;
use UniversalTelegram\Telegram\Outbound\UnresolvedOutboundAbandoner;

/**
 * The single, shared permanent-deletion sequence for a conversation and
 * everything it owns, extracted out of RetentionCleanupHandler's own
 * 90-day purge step so both the scheduled handler and M07's manual
 * "delete archived conversation" admin action (docs/adr/0026) call the
 * exact same code, never duplicated inline.
 *
 * When a destination id is supplied, any forum topic on that destination is
 * best-effort deleted on Telegram first (ForumTopicRemoteDeleter), then the
 * local destination row is removed. Callers that lack exclusive destination
 * ownership must pass null so a shared destination row is retained
 * (M07.1, docs/adr/0031). TopicDeletionHandler remains the strict
 * eligibility / chat-not-found gate for confirmation-queued deletes.
 *
 * Also deletes any AI draft rows for the conversation (M09, docs/adr/0028
 * §4 retention table) via a direct, raw query against the drafts table
 * rather than a AI\Draft\AiDraftRepository dependency — that class's own
 * fixed six-class access allow-list (decision 6) deliberately does not
 * include this service, mirroring the identical raw-table-access pattern
 * AI\Config\AIProviderRepository already uses for its own cross-cutting
 * in-flight-cancellation need. An active (`queued`/`generating`) draft is
 * never left referencing a conversation that no longer exists.
 */
final class ConversationPurgeService {

	/**
	 * Constructor.
	 *
	 * @param ConversationRepository       $conversations Conversation persistence.
	 * @param MessageRepository            $messages      Conversation message persistence.
	 * @param DestinationRepository        $destinations  Deletes a conversation's own destination row.
	 * @param ConversationNoteRepository   $notes         Internal notes; deleted with the conversation.
	 * @param ForumTopicRemoteDeleter|null $remote_topics Best-effort Telegram topic delete before local dest delete.
	 * @param UnresolvedOutboundAbandoner|null $unresolved_abandoner Drops pending outbound rows before dest delete.
	 */
	public function __construct(
		private readonly ConversationRepository $conversations,
		private readonly MessageRepository $messages,
		private readonly DestinationRepository $destinations,
		private readonly ?ConversationNoteRepository $notes = null,
		private readonly ?ForumTopicRemoteDeleter $remote_topics = null,
		private readonly ?UnresolvedOutboundAbandoner $unresolved_abandoner = null
	) {}

	/**
	 * Permanently deletes a conversation, all its message rows, any notes,
	 * any AI draft rows, and — only when `$destination_id` is non-null —
	 * its own destination row (Telegram forum topic first when present).
	 *
	 * @param int      $conversation_id The conversation to purge.
	 * @param int|null $destination_id  The conversation's own destination row id, or null.
	 */
	public function purge( int $conversation_id, ?int $destination_id ): void {
		$this->messages->delete_for_conversation( $conversation_id );
		$this->notes?->delete_for_conversation( $conversation_id );
		$this->delete_ai_drafts_for_conversation( $conversation_id );

		if ( null !== $destination_id ) {
			$this->remote_topics?->try_delete_for_destination_id( $destination_id );
			$this->unresolved_abandoner?->abandon_for_destination( $destination_id );
			$this->destinations->delete( $destination_id );
		}

		$this->conversations->delete( $conversation_id );
	}

	/**
	 * Deletes every AI draft row for a conversation, whatever its status —
	 * an active one is never left orphaned against a deleted conversation.
	 *
	 * @param int $conversation_id The conversation being purged.
	 */
	private function delete_ai_drafts_for_conversation( int $conversation_id ): void {
		global $wpdb;

		$table = $wpdb->prefix . Migrator::AI_DRAFTS_TABLE;

		if ( ! $this->table_exists( $table ) ) {
			return;
		}

		$wpdb->delete( $table, array( 'conversation_id' => $conversation_id ), array( '%d' ) );
	}

	/**
	 * Whether a table exists — guards the AI-drafts delete for any test or
	 * environment that has not yet migrated to db_version 20+.
	 *
	 * @param string $table The fully-prefixed table name.
	 */
	private function table_exists( string $table ): bool {
		global $wpdb;

		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}
}
