<?php
/**
 * Shared conversation purge sequence.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Conversations;

use UniversalTelegram\Telegram\Configuration\DestinationRepository;

/**
 * The single, shared permanent-deletion sequence for a conversation and
 * everything it owns, extracted out of RetentionCleanupHandler's own
 * 90-day purge step so both the scheduled handler and M07's manual
 * "delete archived conversation" admin action (docs/adr/0026) call the
 * exact same code, never duplicated inline. Matching
 * DestinationRepository::delete()'s existing behavior, this never calls
 * the Telegram Bot API: no Telegram-side message or forum topic is ever
 * touched by this class.
 */
final class ConversationPurgeService {

	/**
	 * Constructor.
	 *
	 * @param ConversationRepository $conversations Conversation persistence.
	 * @param MessageRepository      $messages      Conversation message persistence.
	 * @param DestinationRepository  $destinations  Deletes a conversation's own destination row.
	 */
	public function __construct(
		private readonly ConversationRepository $conversations,
		private readonly MessageRepository $messages,
		private readonly DestinationRepository $destinations
	) {}

	/**
	 * Permanently deletes a conversation, all its message rows, and its own
	 * destination row (if any). Never contacts the Telegram Bot API.
	 *
	 * @param int      $conversation_id The conversation to purge.
	 * @param int|null $destination_id  The conversation's own destination row id, or null.
	 */
	public function purge( int $conversation_id, ?int $destination_id ): void {
		$this->messages->delete_for_conversation( $conversation_id );

		if ( null !== $destination_id ) {
			$this->destinations->delete( $destination_id );
		}

		$this->conversations->delete( $conversation_id );
	}
}
