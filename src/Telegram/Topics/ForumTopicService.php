<?php
/**
 * Forum-topic create/delete operations for the Support Chat adapter.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Telegram\Topics;

use UniversalTelegram\Core\Security\CredentialState;
use UniversalTelegram\Telegram\Client\TelegramApiClient;
use UniversalTelegram\Telegram\Configuration\BotProfile;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;

/**
 * The one place the plugin creates or deletes a Telegram forum topic
 * (`createForumTopic` / `deleteForumTopic`). Replaces the removed legacy
 * async topic-lifecycle handlers (ADR-0044): a small synchronous service
 * with explicit success/failure results and a best-effort cleanup helper,
 * so callers (`EnsureChannelCaseService`) never reach for the raw
 * {@see TelegramApiClient} inline.
 */
final class ForumTopicService {

	/**
	 * Constructor.
	 *
	 * @param BotProfileRepository $bots   Decrypts the bot token for the API call.
	 * @param TelegramApiClient    $client Telegram Bot API client.
	 */
	public function __construct(
		private readonly BotProfileRepository $bots,
		private readonly TelegramApiClient $client
	) {}

	/**
	 * Creates a forum topic under a supergroup chat.
	 *
	 * @param BotProfile $bot     The owning bot.
	 * @param string     $chat_id The parent supergroup chat id.
	 * @param string     $name    The topic name.
	 *
	 * @return int|null The new `message_thread_id`, or null on any failure.
	 */
	public function create( BotProfile $bot, string $chat_id, string $name ): ?int {
		$token = $this->bots->decrypt_token( $bot );

		if ( CredentialState::AVAILABLE !== $token->state() || null === $token->plaintext() ) {
			return null;
		}

		$result = $this->client->create_forum_topic( $token->plaintext(), $chat_id, $name );

		if ( ! $result->ok() ) {
			return null;
		}

		$payload   = $result->result();
		$thread_id = is_array( $payload ) ? ( $payload['message_thread_id'] ?? null ) : null;

		return is_int( $thread_id ) ? $thread_id : null;
	}

	/**
	 * Best-effort delete of a forum topic — used to clean up a topic that
	 * was created but whose binding could not then be persisted. Never
	 * throws; a failed delete is left for the operator.
	 *
	 * @param BotProfile $bot              The owning bot.
	 * @param string     $chat_id          The parent supergroup chat id.
	 * @param int        $message_thread_id The topic to delete.
	 *
	 * @return bool Whether the delete call reported success.
	 */
	public function try_delete( BotProfile $bot, string $chat_id, int $message_thread_id ): bool {
		$token = $this->bots->decrypt_token( $bot );

		if ( CredentialState::AVAILABLE !== $token->state() || null === $token->plaintext() ) {
			return false;
		}

		return $this->client->delete_forum_topic( $token->plaintext(), $chat_id, $message_thread_id )->ok();
	}
}
