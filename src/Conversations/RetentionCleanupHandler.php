<?php
/**
 * Retention-based cleanup of conversations and their messages.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Conversations;

use UniversalTelegram\Telegram\Configuration\DestinationRepository;

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
 * permanently deletes the conversation, all its message rows, and its
 * own destination row for conversations archived at least
 * $conversation_retention_days ago. Each step queries currently-matching
 * rows and acts on them, so a rerun against already-cleaned data is a
 * safe no-op. Step 0's own eligibility query only ever selects the three
 * open/waiting statuses, so an already-resolved, archived, or deleted
 * conversation is never reopened or reprocessed by this handler.
 */
final class RetentionCleanupHandler {

	public const HOOK = 'universal_telegram_conversation_retention_cleanup';

	/**
	 * Constructor.
	 *
	 * @param ConversationRepository $conversations              Conversation persistence.
	 * @param MessageRepository      $messages                    Conversation message persistence.
	 * @param DestinationRepository  $destinations                Deletes a conversation's own destination row.
	 * @param int                    $message_retention_days      Days since archival before message bodies are nulled.
	 * @param int                    $conversation_retention_days Days since archival before the conversation is permanently deleted.
	 * @param int                    $inactivity_days             Days of no visitor/operator message before an open/waiting conversation auto-resolves (M06.3, ADR-0024).
	 */
	public function __construct(
		private readonly ConversationRepository $conversations,
		private readonly MessageRepository $messages,
		private readonly DestinationRepository $destinations,
		private readonly int $message_retention_days = 30,
		private readonly int $conversation_retention_days = 90,
		private readonly int $inactivity_days = 30
	) {}

	/**
	 * Runs one cleanup pass.
	 */
	public function run(): void {
		foreach ( $this->conversations->inactive_open_conversations( $this->inactivity_days ) as $conversation ) {
			if ( ConversationStatus::NEW === $conversation->status() ) {
				// `new` is not directly resolvable per the frozen transition
				// map; a `new` row occupies the owner_active_slot index
				// (M06.3.1, ADR-0025) and, if its topic creation permanently
				// failed, would otherwise never free that slot. Both hops
				// are individually valid per the existing map.
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
		}

		foreach ( $this->conversations->archived_older_than( $this->conversation_retention_days ) as $conversation ) {
			$this->messages->delete_for_conversation( $conversation->id() );

			if ( null !== $conversation->destination_id() ) {
				$this->destinations->delete( $conversation->destination_id() );
			}

			$this->conversations->delete( $conversation->id() );
		}
	}
}
