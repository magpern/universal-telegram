<?php
/**
 * Resolves a conversation-start request's optional chat_profile.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Conversations;

use UniversalTelegram\Telegram\Configuration\BotProfile;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;
use UniversalTelegram\Telegram\Configuration\DestinationKind;
use UniversalTelegram\Telegram\Configuration\DestinationRepository;

/**
 * `chat_profile` (M05 plan §4) is matched against a configured bot's own
 * `name`, reusing the existing bot-profile configuration rather than
 * introducing a new settings surface. When omitted, the first configured
 * bot is used. conversation_chat_id() additionally resolves the bot's
 * conversation-support destination — its one enabled supergroup
 * destination with no message_thread_id of its own, i.e. the group a
 * topic is created inside, not a specific existing topic — used only once
 * a topic is actually about to be created (M05 plan §5).
 */
final class ChatProfileResolver {

	/**
	 * Constructor.
	 *
	 * @param BotProfileRepository  $bots         The configured Telegram bots.
	 * @param DestinationRepository $destinations The configured destinations.
	 */
	public function __construct(
		private readonly BotProfileRepository $bots,
		private readonly DestinationRepository $destinations
	) {}

	/**
	 * The default bot used when no `chat_profile` is given.
	 *
	 * @return BotProfile|null Null if no bot is configured at all.
	 */
	public function default_bot(): ?BotProfile {
		$bots = $this->bots->all();

		return $bots[0] ?? null;
	}

	/**
	 * Finds the configured bot whose `name` matches the requested profile.
	 *
	 * @param string $chat_profile The requested profile name.
	 *
	 * @return BotProfile|null Null if no bot matches.
	 */
	public function find_by_profile( string $chat_profile ): ?BotProfile {
		foreach ( $this->bots->all() as $bot ) {
			if ( $bot->name() === $chat_profile ) {
				return $bot;
			}
		}

		return null;
	}

	/**
	 * The chat id a conversation's Telegram topic is created in: the bot's
	 * one enabled supergroup destination with no message_thread_id of its
	 * own (M05 plan §5).
	 *
	 * @param int $bot_id The bot's primary key.
	 *
	 * @return string|null Null if no such destination is configured.
	 */
	public function conversation_chat_id( int $bot_id ): ?string {
		foreach ( $this->destinations->for_bot( $bot_id ) as $destination ) {
			if ( DestinationKind::SUPERGROUP === $destination->kind()
				&& null === $destination->message_thread_id()
				&& $destination->enabled() ) {
				return $destination->chat_id();
			}
		}

		return null;
	}

	/**
	 * The same destination row conversation_chat_id() resolves, but its
	 * primary key rather than its chat_id — used by M08's administrative
	 * bot commands (ADR-0027) to send a General-topic acknowledgement
	 * through the existing outbound MessageDispatcher, which addresses a
	 * destination by id, not by chat_id.
	 *
	 * @param int $bot_id The bot's primary key.
	 *
	 * @return int|null Null if no such destination is configured.
	 */
	public function conversation_destination_id( int $bot_id ): ?int {
		foreach ( $this->destinations->for_bot( $bot_id ) as $destination ) {
			if ( DestinationKind::SUPERGROUP === $destination->kind()
				&& null === $destination->message_thread_id()
				&& $destination->enabled() ) {
				return $destination->id();
			}
		}

		return null;
	}
}
