<?php
/**
 * Outbound message dispatch.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Telegram\Outbound;

use UniversalTelegram\Privacy\Classification;
use UniversalTelegram\Queue\Dispatcher;
use UniversalTelegram\Queue\DispatchResult;
use UniversalTelegram\Queue\JobEnvelope;

/**
 * The one and only way any M01 code sends a Telegram message: writes the
 * content to OutboundMessageRepository first, then enqueues a JobEnvelope
 * carrying only opaque identifiers — never text, never a token
 * (docs/adr/0012). Reuses Queue\Dispatcher exactly as-is.
 */
final class MessageDispatcher {

	public const JOB_TYPE = 'telegram_send_message';

	/**
	 * Constructor.
	 *
	 * @param OutboundMessageRepository $messages   Durable, encrypted message storage.
	 * @param Dispatcher                $dispatcher M00's generic queue dispatcher, used as-is.
	 */
	public function __construct(
		private readonly OutboundMessageRepository $messages,
		private readonly Dispatcher $dispatcher
	) {}

	/**
	 * Stores a message and enqueues its send.
	 *
	 * @param int         $bot_id          The owning bot's primary key.
	 * @param int         $destination_id  The target destination's primary key.
	 * @param string      $text            The message text.
	 * @param string|null $parse_mode      Telegram's own parse_mode parameter.
	 *
	 * @return DispatchResult|null Null if the message itself could not be stored (schema unavailable).
	 */
	public function send( int $bot_id, int $destination_id, string $text, ?string $parse_mode = null ): ?DispatchResult {
		$message = $this->messages->create( $bot_id, $destination_id, $text, $parse_mode );

		if ( null === $message ) {
			return null;
		}

		$envelope = new JobEnvelope(
			self::JOB_TYPE,
			array(
				'message_uuid'   => $message->message_uuid(),
				'bot_id'         => $bot_id,
				'destination_id' => $destination_id,
			),
			array(
				'message_uuid'   => Classification::INTERNAL,
				'bot_id'         => Classification::INTERNAL,
				'destination_id' => Classification::INTERNAL,
			)
		);

		return $this->dispatcher->enqueue( $envelope );
	}
}
