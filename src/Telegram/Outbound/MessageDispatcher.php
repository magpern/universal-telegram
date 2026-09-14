<?php
/**
 * Outbound message dispatch.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Telegram\Outbound;

use UniversalTelegram\Privacy\Classification;
use UniversalTelegram\Queue\DeliveryClass;
use UniversalTelegram\Queue\Dispatcher;
use UniversalTelegram\Queue\DispatchResult;
use UniversalTelegram\Queue\JobEnvelope;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;
use UniversalTelegram\Telegram\Configuration\DestinationRepository;

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
	 * @param OutboundMessageRepository  $messages       Durable, encrypted message storage.
	 * @param Dispatcher                 $dispatcher     M00's generic queue dispatcher, used as-is.
	 * @param SendMessageHandler|null    $immediate      ADR-0023 amendment's own primary delivery mechanism, finally wired to a caller (M09): the same claim-protected, non-throwing try_once() the durable queue worker itself calls, invoked once, synchronously, only when a caller opts in via send()'s own $attempt_immediate. Null in any context that never wires it (e.g. an older test double).
	 * @param BotProfileRepository|null  $bots           Resolves the bot for an immediate attempt.
	 * @param DestinationRepository|null $destinations   Resolves the destination for an immediate attempt.
	 */
	public function __construct(
		private readonly OutboundMessageRepository $messages,
		private readonly Dispatcher $dispatcher,
		private readonly ?SendMessageHandler $immediate = null,
		private readonly ?BotProfileRepository $bots = null,
		private readonly ?DestinationRepository $destinations = null
	) {}

	/**
	 * Stores a message and enqueues its send.
	 *
	 * @param int                       $bot_id             The owning bot's primary key.
	 * @param int                       $destination_id     The target destination's primary key.
	 * @param string                    $text               The message text.
	 * @param string|null               $parse_mode         Telegram's own parse_mode parameter.
	 * @param array<string, mixed>|null $reply_markup        Telegram's own `reply_markup` payload (currently only `inline_keyboard`), or null for no keyboard.
	 * @param bool                      $attempt_immediate  When true, and an immediate-delivery collaborator was wired at construction, also make one bounded, claim-protected, non-throwing delivery attempt synchronously, right here, before returning — for genuinely interactive contexts only (an administrative bot-command/button reply), never for a notification/event send triggered from an unrelated request (docs/adr/0023 amendment scopes this to interactive traffic; a batch/event caller must leave this false, its own default). The durable enqueue below is unconditional either way: a declined, unavailable, or failed immediate attempt changes nothing about eventual delivery via the normal queue.
	 *
	 * @return DispatchResult|null Null if the message itself could not be stored (schema unavailable).
	 */
	public function send( int $bot_id, int $destination_id, string $text, ?string $parse_mode = null, ?array $reply_markup = null, bool $attempt_immediate = false ): ?DispatchResult {
		$message = $this->messages->create( $bot_id, $destination_id, $text, $parse_mode, DeliveryClass::STANDARD, $reply_markup );

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

		$result = $this->dispatcher->enqueue( $envelope );

		if ( $attempt_immediate ) {
			$this->maybe_attempt_immediate_delivery( $message, $bot_id, $destination_id );
		}

		return $result;
	}

	/**
	 * The ADR-0023 amendment's primary interactive-latency mechanism: one
	 * bounded, claim-protected, non-throwing send attempt, made
	 * synchronously in the caller's own request. Never lets a genuine
	 * configuration-error throw (SendMessageHandler::try_once()'s own
	 * documented exception for an undecryptable token/body) escape to the
	 * caller — an immediate attempt is strictly an optimization, and its
	 * complete absence changes nothing about the message's already-durable
	 * enqueue above.
	 *
	 * @param OutboundMessage $message        The just-enqueued message.
	 * @param int             $bot_id         The owning bot's primary key.
	 * @param int             $destination_id The target destination's primary key.
	 */
	private function maybe_attempt_immediate_delivery( OutboundMessage $message, int $bot_id, int $destination_id ): void {
		if ( null === $this->immediate || null === $this->bots || null === $this->destinations ) {
			return;
		}

		$bot         = $this->bots->find( $bot_id );
		$destination = $this->destinations->find( $destination_id );

		if ( null === $bot || null === $destination ) {
			return;
		}

		try {
			$this->immediate->try_once( $message, $bot, $destination, 1 );
		} catch ( \Throwable $exception ) {
			// Genuinely exceptional (undecryptable token/body) or any other
			// unexpected failure: the message is already durably enqueued
			// above, so the normal queue worker remains fully sufficient.
			unset( $exception );
		}
	}
}
