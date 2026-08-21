<?php
/**
 * Chat widget availability gate.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\ChatWidget;

use UniversalTelegram\Conversations\ChatProfileResolver;
use UniversalTelegram\Core\Configuration\Settings;

/**
 * Whether the M06 chat widget should be considered available on the
 * current request at all: the administrator's `chat_widget_enabled`
 * toggle (M06 plan §1) AND at least one configured bot with an eligible
 * conversation-support destination — reusing
 * ChatProfileResolver::default_bot()/conversation_chat_id() exactly as
 * M05's own start handler does, rather than introducing a duplicate
 * eligibility rule.
 */
final class ChatWidgetAvailability {

	/**
	 * Constructor.
	 *
	 * @param Settings            $settings      Reads the current plugin-wide configuration.
	 * @param ChatProfileResolver $chat_profiles Resolves the default bot and its destination.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly ChatProfileResolver $chat_profiles
	) {}

	/**
	 * Whether the widget is enabled and has at least one eligible,
	 * configured Telegram destination to start a conversation against.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		$settings_values = $this->settings->get();

		if ( empty( $settings_values['chat_widget_enabled'] ) ) {
			return false;
		}

		$bot = $this->chat_profiles->default_bot();

		if ( null === $bot ) {
			return false;
		}

		return null !== $this->chat_profiles->conversation_chat_id( $bot->id() );
	}
}
