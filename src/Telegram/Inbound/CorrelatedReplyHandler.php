<?php
/**
 * Handler for native replies to correlated notifications.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Telegram\Inbound;

/**
 * Implemented by a feature that sends notifications carrying a correlation
 * token (docs/adr/0046) and wants the human's native Telegram reply routed
 * back to the token's subject.
 */
interface CorrelatedReplyHandler {

	/**
	 * Handles one native reply to a correlated notification. Every
	 * outcome, including a rejection, is answered in chat by the handler.
	 *
	 * @param int                  $bot_id         The receiving bot's primary key.
	 * @param int                  $destination_id The destination the notification was delivered to.
	 * @param string               $token_suffix   The part of the correlation token after the prefix (e.g. the ticket id).
	 * @param array<string, mixed> $message        The decoded Telegram `message` object.
	 *
	 * @return bool True when the update was claimed (always, once the token matched).
	 */
	public function handle( int $bot_id, int $destination_id, string $token_suffix, array $message ): bool;
}
