<?php
/**
 * Outbound message dispatch.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Telegram\Outbound;

use UniversalTelegram\Privacy\Classification;
use UniversalTelegram\Queue\AttemptOutcome;
use UniversalTelegram\Queue\DeliveryClass;
use UniversalTelegram\Queue\Dispatcher;
use UniversalTelegram\Queue\DispatchResult;
use UniversalTelegram\Queue\JobEnvelope;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;
use UniversalTelegram\Telegram\Configuration\Destination;
use UniversalTelegram\Telegram\Configuration\DestinationKind;
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
	 * Stores a message addressed to one Telegram user's own private chat
	 * (Telegram's own convention: a private chat's id equals the user's
	 * numeric id), enqueues it durably exactly like send(), and always
	 * attempts immediate delivery — private replies exist specifically to
	 * be interactive (M09).
	 *
	 * Telegram will only accept this if the recipient has already opened a
	 * private chat with the bot at least once; the caller's own outcome
	 * check is what should drive any user-facing fallback (this method
	 * itself never falls back to a group destination — it has no group
	 * context to fall back to).
	 *
	 * @param int                       $bot_id             The owning bot's primary key.
	 * @param string                    $telegram_user_id    The recipient's own numeric Telegram id, as a string (Destination's own chat_id type).
	 * @param string                    $text               The message text.
	 * @param array<string, mixed>|null $reply_markup Telegram's own `reply_markup` payload, or null for none.
	 *
	 * @return AttemptOutcome|null Null when the immediate-delivery collaborators were never wired, the destination could not be resolved/created, or the message itself could not be stored.
	 */
	public function send_private( int $bot_id, string $telegram_user_id, string $text, ?array $reply_markup = null ): ?AttemptOutcome {
		if ( null === $this->immediate || null === $this->bots || null === $this->destinations ) {
			return null;
		}

		$destination = $this->find_or_create_private_destination( $bot_id, $telegram_user_id );

		if ( null === $destination ) {
			return null;
		}

		$message = $this->messages->create( $bot_id, $destination->id(), $text, null, DeliveryClass::STANDARD, $reply_markup );

		if ( null === $message ) {
			return null;
		}

		$envelope = new JobEnvelope(
			self::JOB_TYPE,
			array(
				'message_uuid'   => $message->message_uuid(),
				'bot_id'         => $bot_id,
				'destination_id' => $destination->id(),
			),
			array(
				'message_uuid'   => Classification::INTERNAL,
				'bot_id'         => Classification::INTERNAL,
				'destination_id' => Classification::INTERNAL,
			)
		);

		$this->dispatcher->enqueue( $envelope );

		return $this->maybe_attempt_immediate_delivery( $message, $bot_id, $destination->id() );
	}

	/**
	 * The bot's own existing private destination for this user, or a newly
	 * created one — a private destination's (bot_id, chat_id) pair is
	 * exactly as stable and reusable as a group/supergroup one, just never
	 * admin-configured up front.
	 *
	 * @param int    $bot_id            The owning bot's primary key.
	 * @param string $telegram_user_id   The recipient's own numeric Telegram id, as a string.
	 *
	 * @return Destination|null
	 */
	private function find_or_create_private_destination( int $bot_id, string $telegram_user_id ): ?Destination {
		foreach ( $this->destinations->for_bot( $bot_id ) as $destination ) {
			if ( DestinationKind::PRIVATE === $destination->kind() && $telegram_user_id === $destination->chat_id() ) {
				return $destination;
			}
		}

		return $this->destinations->create( $bot_id, DestinationKind::PRIVATE, $telegram_user_id, null, 'Operator DM ' . $telegram_user_id );
	}

	/**
	 * The ADR-0023 amendment's primary interactive-latency mechanism: one
	 * bounded, claim-protected, non-throwing send attempt, made
	 * synchronously in the caller's own request. Never lets a genuine
	 * configuration-error throw (SendMessageHandler::try_once()'s own
	 * documented exception for an undecryptable token/body) escape to the
	 * caller — an immediate attempt is strictly an optimization for send(),
	 * and its complete absence changes nothing about the message's
	 * already-durable enqueue; send_private() additionally reports the
	 * outcome, since its own caller needs it to decide on a fallback.
	 *
	 * @param OutboundMessage $message        The just-enqueued message.
	 * @param int             $bot_id         The owning bot's primary key.
	 * @param int             $destination_id The target destination's primary key.
	 *
	 * @return AttemptOutcome|null Null when the immediate-delivery collaborators were never wired, or the bot/destination could not be resolved.
	 */
	private function maybe_attempt_immediate_delivery( OutboundMessage $message, int $bot_id, int $destination_id ): ?AttemptOutcome {
		if ( null === $this->immediate || null === $this->bots || null === $this->destinations ) {
			return null;
		}

		$bot         = $this->bots->find( $bot_id );
		$destination = $this->destinations->find( $destination_id );

		if ( null === $bot || null === $destination ) {
			return null;
		}

		try {
			return $this->immediate->try_once( $message, $bot, $destination, 1 );
		} catch ( \Throwable $exception ) {
			// Genuinely exceptional (undecryptable token/body) or any other
			// unexpected failure: the message is already durably enqueued
			// above, so the normal queue worker remains fully sufficient.
			unset( $exception );

			return null;
		}
	}
}
