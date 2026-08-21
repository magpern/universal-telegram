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

/**
 * `chat_profile` (M05 plan §4) is matched against a configured bot's own
 * `name`, reusing the existing bot-profile configuration rather than
 * introducing a new settings surface. When omitted, the first configured
 * bot is used. This class resolves only which bot a conversation belongs
 * to; the conversation-support destination (the chat a topic is created
 * in) is resolved separately, only once a topic is actually about to be
 * created (M05 plan §5).
 */
final class ChatProfileResolver {

	/**
	 * Constructor.
	 *
	 * @param BotProfileRepository $bots The configured Telegram bots.
	 */
	public function __construct(
		private readonly BotProfileRepository $bots
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
}
