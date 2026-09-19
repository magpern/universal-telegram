<?php
/**
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Tests\Integration\Support;

use UniversalTelegram\Telegram\Inbound\CorrelatedReplyHandler;

/**
 * Records every routed reply and claims it.
 */
final class RecordingReplyHandler implements CorrelatedReplyHandler {

	/**
	 * Calls: bot id, destination id, token suffix, message.
	 *
	 * @var array<int, array{0: int, 1: int, 2: string, 3: array<string, mixed>}>
	 */
	public array $calls = array();

	/**
	 * {@inheritDoc}
	 *
	 * @param int                  $bot_id         Bot.
	 * @param int                  $destination_id Destination.
	 * @param string               $token_suffix   Suffix.
	 * @param array<string, mixed> $message        Message.
	 */
	public function handle( int $bot_id, int $destination_id, string $token_suffix, array $message ): bool {
		$this->calls[] = array( $bot_id, $destination_id, $token_suffix, $message );

		return true;
	}
}
