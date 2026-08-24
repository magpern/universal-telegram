<?php
/**
 * Best-effort Telegram forum-topic deletion for a destination row.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Conversations;

use Throwable;
use UniversalTelegram\Core\Security\CredentialState;
use UniversalTelegram\Telegram\Client\TelegramApiClient;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;
use UniversalTelegram\Telegram\Configuration\Destination;
use UniversalTelegram\Telegram\Configuration\DestinationKind;
use UniversalTelegram\Telegram\Configuration\DestinationRepository;

/**
 * When WordPress deletes a destination that points at a forum topic, this
 * collaborator attempts deleteForumTopic first so Telegram is not left with
 * an orphan topic. Failures are swallowed: the caller still removes the
 * local row. Conversation-gated delete (TopicDeletionHandler) remains the
 * strict eligibility / chat-not-found path (M07.1, docs/adr/0031).
 */
final class ForumTopicRemoteDeleter {

	/**
	 * Constructor.
	 *
	 * @param BotProfileRepository  $bots         Bot profiles and token decryption.
	 * @param DestinationRepository $destinations Destination persistence.
	 * @param TelegramApiClient     $client       Telegram Bot API client.
	 */
	public function __construct(
		private readonly BotProfileRepository $bots,
		private readonly DestinationRepository $destinations,
		private readonly TelegramApiClient $client
	) {}

	/**
	 * Best-effort remote delete for a destination id. No-op when the row is
	 * missing or is not a forum-topic destination. Never throws.
	 *
	 * @param int $destination_id Destination primary key.
	 */
	public function try_delete_for_destination_id( int $destination_id ): void {
		$destination = $this->destinations->find( $destination_id );

		if ( null === $destination ) {
			return;
		}

		$this->try_delete_for_destination( $destination );
	}

	/**
	 * Best-effort remote delete for a loaded destination. Never throws.
	 *
	 * @param Destination $destination The destination being removed in WordPress.
	 */
	public function try_delete_for_destination( Destination $destination ): void {
		$thread = $destination->message_thread_id();

		if ( null === $thread || $thread <= 1 ) {
			return;
		}

		if ( DestinationKind::SUPERGROUP !== $destination->kind() ) {
			return;
		}

		$bot = $this->bots->find( $destination->bot_id() );

		if ( null === $bot ) {
			return;
		}

		$token_result = $this->bots->decrypt_token( $bot );

		if ( CredentialState::AVAILABLE !== $token_result->state() || null === $token_result->plaintext() ) {
			return;
		}

		try {
			$this->client->delete_forum_topic(
				$token_result->plaintext(),
				$destination->chat_id(),
				$thread
			);
		} catch ( Throwable $exception ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- best-effort; local delete proceeds.
			unset( $exception );
		}
	}
}
