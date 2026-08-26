<?php
/**
 * Retention-based cleanup of conversations and their messages.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Conversations;

use UniversalTelegram\Migration\QuiescenceGate;

/**
 * A recurring Action Scheduler action, independent of the queue's own
 * job-handler contract, mirroring Telegram\Outbound\RetentionCleanupHandler's
 * own shape (M05 plan §9, docs/adr/0021). One daily pass performs, in
 * order: (0) `open|waiting_for_visitor|waiting_for_operator -> resolved`
 * for every conversation inactive (no visitor or operator message — its
 * own updated_at, already bumped by every existing status/topic-state
 * transition) for at least $inactivity_days — M06.3's automatic archival
 * step, ADR-0024, always routed through the existing, frozen
 * ConversationStatus transition map, never a raw status write; (1)
 * `resolved -> archived` for every currently-resolved conversation (now
 * including any this same pass just resolved) — the sole code path in
 * this plugin ever permitted to make that transition — piggybacking
 * secret revocation at the same moment; (2) nulls message bodies for
 * conversations archived at least $message_retention_days ago; (3)
 * for conversations archived at least $conversation_retention_days ago,
 * either enqueues remote topic deletion via TopicDeletionDispatcher when
 * ConversationTopicEligibility passes, or locally purges via
 * ConversationPurgeService (shared dest never deleted) — the same path
 * as the operator confirm-delete action (M07.1, docs/adr/0031). Each step
 * queries currently-matching rows and acts on them, so a rerun against
 * already-cleaned data is a safe no-op. Step 0's own eligibility query
 * only ever selects the three open/waiting statuses, so an already-resolved,
 * archived, or deleted conversation is never reopened or reprocessed.
 */
final class RetentionCleanupHandler {

	public const HOOK = 'universal_telegram_conversation_retention_cleanup';

	/**
	 * Constructor.
	 *
	 * @param ConversationRepository       $conversations               Conversation persistence.
	 * @param MessageRepository            $messages                     Conversation message persistence.
	 * @param ConversationPurgeService     $purge_service               Shared permanent-deletion sequence.
	 * @param ConversationTopicEligibility $eligibility                 Remote-delete structural gate.
	 * @param TopicDeletionDispatcher      $topic_deletion              Queues eligible remote deletes.
	 * @param int                          $message_retention_days       Days since archival before message bodies are nulled.
	 * @param int                          $conversation_retention_days  Days since archival before permanent delete.
	 * @param int                          $inactivity_days              Days before open/waiting auto-resolves.
	 * @param QuiescenceGate|null          $quiescence                  Legacy-chat quiescence write-blocking gate (docs/adr/0040). Null only in a not-yet-migrated install.
	 */
	public function __construct(
		private readonly ConversationRepository $conversations,
		private readonly MessageRepository $messages,
		private readonly ConversationPurgeService $purge_service,
		private readonly ConversationTopicEligibility $eligibility,
		private readonly TopicDeletionDispatcher $topic_deletion,
		private readonly int $message_retention_days = 30,
		private readonly int $conversation_retention_days = 90,
		private readonly int $inactivity_days = 30,
		private readonly ?QuiescenceGate $quiescence = null
	) {}

	/**
	 * Runs one cleanup pass. Skips the entire cycle outside `idle`
	 * (docs/adr/0040 §5) — never marked failed, simply not run; the next
	 * scheduled cycle re-checks.
	 */
	public function run(): void {
		if ( null !== $this->quiescence && ! $this->quiescence->is_idle() ) {
			return;
		}

		foreach ( $this->conversations->inactive_open_conversations( $this->inactivity_days ) as $conversation ) {
			if ( ConversationStatus::NEW === $conversation->status() ) {
				$this->conversations->transition( $conversation->id(), ConversationStatus::NEW, ConversationStatus::OPEN );
				$this->conversations->transition( $conversation->id(), ConversationStatus::OPEN, ConversationStatus::RESOLVED );
				continue;
			}

			$this->conversations->transition( $conversation->id(), $conversation->status(), ConversationStatus::RESOLVED );
		}

		foreach ( $this->conversations->resolved() as $conversation ) {
			if ( $this->conversations->transition( $conversation->id(), ConversationStatus::RESOLVED, ConversationStatus::ARCHIVED ) ) {
				$this->conversations->revoke_secret( $conversation->id() );
			}
		}

		foreach ( $this->conversations->archived_older_than( $this->message_retention_days ) as $conversation ) {
			$this->messages->null_bodies_for_conversation( $conversation->id() );
			$this->null_ai_draft_bodies_for_conversation( $conversation->id() );
		}

		foreach ( $this->conversations->archived_older_than( $this->conversation_retention_days ) as $conversation ) {
			if ( TopicLifecycleState::DELETE_PENDING === $conversation->topic_lifecycle_state() ) {
				continue;
			}

			if ( $this->eligibility->is_remote_deletable( $conversation ) ) {
				$this->topic_deletion->maybe_delete( $conversation );
				continue;
			}

			$this->purge_service->purge(
				$conversation->id(),
				$this->eligibility->destination_id_for_purge( $conversation )
			);
		}
	}

	/**
	 * Nulls AI draft body ciphertext for a conversation's terminal-status
	 * drafts (M09, docs/adr/0028 §4 retention table) — a direct, raw query
	 * against the drafts table rather than a AI\Draft\AiDraftRepository
	 * dependency, mirroring AI\Config\AIProviderRepository's identical
	 * cross-cutting pattern; that class's own fixed six-class access
	 * allow-list (decision 6) deliberately does not include this handler.
	 * Keeps metadata/traceability, matching the message-body-nulling
	 * precedent immediately above.
	 *
	 * @param int $conversation_id The archived conversation.
	 */
	private function null_ai_draft_bodies_for_conversation( int $conversation_id ): void {
		global $wpdb;

		$table = $wpdb->prefix . 'universal_telegram_ai_drafts';

		if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			return;
		}

		$wpdb->update(
			$table,
			array( 'body_ciphertext' => null ),
			array( 'conversation_id' => $conversation_id ),
			array( '%s' ),
			array( '%d' )
		);
	}
}
