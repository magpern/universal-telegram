<?php
/**
 * Port for delivering the digest.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Integrations\FluentContactInbox\Digest;

/**
 * Production adapter: {@see MessageDispatcherDigestSender}.
 */
interface DigestSender {

	/**
	 * Queues one MarkdownV2 message; false when the bot/destination is not eligible or storing failed.
	 *
	 * @param int    $bot_id         The bot's primary key.
	 * @param int    $destination_id The destination's primary key.
	 * @param string $text           MarkdownV2-escaped text.
	 *
	 * @return bool
	 */
	public function send( int $bot_id, int $destination_id, string $text ): bool;
}
