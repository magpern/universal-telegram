<?php
/**
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Tests\Integrations\FluentContactInbox\Support;

use UniversalTelegram\Integrations\FluentContactInbox\Digest\DigestSender;

/**
 * Recording digest sender.
 */
final class FakeDigestSender implements DigestSender {

	/**
	 * Sent digests: bot id, destination id, text.
	 *
	 * @var array<int, array{0: int, 1: int, 2: string}>
	 */
	public array $sent = array();

	/**
	 * {@inheritDoc}
	 *
	 * @param int    $bot_id         Bot.
	 * @param int    $destination_id Destination.
	 * @param string $text           Text.
	 */
	public function send( int $bot_id, int $destination_id, string $text ): bool {
		$this->sent[] = array( $bot_id, $destination_id, $text );

		return true;
	}
}
