<?php
/**
 * Structural eligibility for remote Telegram topic deletion.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Conversations;

use UniversalTelegram\Telegram\Configuration\Destination;
use UniversalTelegram\Telegram\Configuration\DestinationKind;
use UniversalTelegram\Telegram\Configuration\DestinationRepository;

/**
 * Gates every remote deleteForumTopic call. Eligibility is structural
 * (ids, kind, equality, exclusive COUNT) and never label/name based
 * (M07.1, docs/adr/0031).
 */
final class ConversationTopicEligibility {

	/**
	 * Constructor.
	 *
	 * @param ConversationRepository $conversations Conversation persistence.
	 * @param DestinationRepository  $destinations  Destination persistence.
	 */
	public function __construct(
		private readonly ConversationRepository $conversations,
		private readonly DestinationRepository $destinations
	) {}

	/**
	 * Whether this archived conversation may call deleteForumTopic for its
	 * own plugin-created topic destination.
	 *
	 * @param Conversation $conversation The conversation under consideration.
	 *
	 * @return bool
	 */
	public function is_remote_deletable( Conversation $conversation ): bool {
		return null !== $this->eligible_destination( $conversation );
	}

	/**
	 * Returns the destination id to pass to ConversationPurgeService after a
	 * successful (or already-absent) remote delete. Null when the dest must
	 * be retained (shared ownership or ineligible).
	 *
	 * @param Conversation $conversation The conversation being purged.
	 *
	 * @return int|null
	 */
	public function destination_id_for_purge( Conversation $conversation ): ?int {
		$destination = $this->eligible_destination( $conversation );

		return null === $destination ? null : $destination->id();
	}

	/**
	 * Whether exactly one conversation row references this destination id,
	 * and that row is `$conversation`.
	 *
	 * @param Conversation $conversation The candidate owner.
	 * @param int          $destination_id Destination primary key.
	 *
	 * @return bool
	 */
	public function has_exclusive_destination_ownership( Conversation $conversation, int $destination_id ): bool {
		if ( $conversation->destination_id() !== $destination_id ) {
			return false;
		}

		if ( 1 !== $this->conversations->count_by_destination_id( $destination_id ) ) {
			return false;
		}

		$owner = $this->conversations->find_by_destination_id( $destination_id );

		return null !== $owner && $owner->id() === $conversation->id();
	}

	/**
	 * Resolved eligible destination, or null when remote delete is forbidden.
	 *
	 * @param Conversation $conversation The conversation under consideration.
	 *
	 * @return Destination|null
	 */
	public function eligible_destination( Conversation $conversation ): ?Destination {
		if ( ConversationStatus::ARCHIVED !== $conversation->status() ) {
			return null;
		}

		if ( 'created' !== $conversation->topic_creation_state() ) {
			return null;
		}

		$topic_id = $conversation->telegram_topic_id();

		if ( null === $topic_id || $topic_id <= 1 ) {
			return null;
		}

		$destination_id = $conversation->destination_id();

		if ( null === $destination_id ) {
			return null;
		}

		if ( ! $this->has_exclusive_destination_ownership( $conversation, $destination_id ) ) {
			return null;
		}

		$destination = $this->destinations->find( $destination_id );

		if ( null === $destination ) {
			return null;
		}

		if ( $destination->bot_id() !== $conversation->bot_id() ) {
			return null;
		}

		if ( DestinationKind::SUPERGROUP !== $destination->kind() ) {
			return null;
		}

		$thread = $destination->message_thread_id();

		if ( null === $thread || $thread <= 1 ) {
			return null;
		}

		if ( $thread !== $topic_id ) {
			return null;
		}

		if ( $destination->id() !== $destination_id ) {
			return null;
		}

		return $destination;
	}
}
