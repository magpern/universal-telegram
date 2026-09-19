<?php
/**
 * Routes a native reply to a correlated notification.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Telegram\Inbound;

use UniversalTelegram\Telegram\Commands\CommandParser;
use UniversalTelegram\Telegram\Configuration\BotProfile;
use UniversalTelegram\Telegram\Configuration\DestinationRepository;
use UniversalTelegram\Telegram\Outbound\OutboundMessageRepository;

/**
 * Runs inside {@see WebhookController::process_update()} strictly after the
 * `(bot_id, update_id)` dedup guard, so a redelivered update never reaches a
 * handler twice (docs/adr/0013, docs/adr/0046). Resolves the destination by
 * the exact `(bot_id, chat_id, message_thread_id)` triple, then the
 * notification by `(destination_id, replied-to Telegram message id)`, and
 * dispatches by correlation-token prefix. Anything that does not match — no
 * handler registered, no reply, unknown destination or message, no token, an
 * unknown prefix, or a bot command — falls through (returns false) so
 * existing handling is unchanged.
 */
final class NotificationReplyRouter {

	/**
	 * Handlers keyed by correlation-token prefix.
	 *
	 * @var array<string, CorrelatedReplyHandler>
	 */
	private array $handlers = array();

	/**
	 * Constructor.
	 *
	 * @param DestinationRepository     $destinations Resolves the (bot, chat, thread) destination.
	 * @param OutboundMessageRepository $messages     Resolves the replied-to notification.
	 */
	public function __construct(
		private readonly DestinationRepository $destinations,
		private readonly OutboundMessageRepository $messages
	) {}

	/**
	 * Registers the handler for one correlation-token prefix (e.g. `ticket`).
	 *
	 * @param string                 $prefix  The token prefix, without the colon.
	 * @param CorrelatedReplyHandler $handler The handler.
	 */
	public function register_handler( string $prefix, CorrelatedReplyHandler $handler ): void {
		$this->handlers[ $prefix ] = $handler;
	}

	/**
	 * Tries to route one message update.
	 *
	 * @param BotProfile           $bot               The receiving bot.
	 * @param string|null          $chat_id           The update's chat id.
	 * @param int|null             $message_thread_id The update's forum topic id.
	 * @param array<string, mixed> $decoded           The full decoded update body.
	 *
	 * @return bool True when claimed by a handler.
	 */
	public function try_handle( BotProfile $bot, ?string $chat_id, ?int $message_thread_id, array $decoded ): bool {
		if ( array() === $this->handlers || null === $chat_id ) {
			return false;
		}

		$message = $decoded['message'] ?? null;

		if ( ! is_array( $message ) ) {
			return false;
		}

		$replied_to = $message['reply_to_message']['message_id'] ?? null;

		if ( ! is_int( $replied_to ) ) {
			return false;
		}

		// A bot command typed as a reply must still reach command dispatch.
		if ( null !== CommandParser::parse( $message, $bot->telegram_username() ) ) {
			return false;
		}

		$destination = $this->destinations->find_by_bot_chat_thread( $bot->id(), $chat_id, $message_thread_id );

		if ( null === $destination ) {
			return false;
		}

		$notification = $this->messages->find_by_destination_and_telegram_message_id( $destination->id(), $replied_to );

		if ( null === $notification || $notification->bot_id() !== $bot->id() ) {
			return false;
		}

		$token = $notification->correlation_token();

		if ( null === $token || false === strpos( $token, ':' ) ) {
			return false;
		}

		list( $prefix, $suffix ) = explode( ':', $token, 2 );

		if ( ! isset( $this->handlers[ $prefix ] ) ) {
			return false;
		}

		return $this->handlers[ $prefix ]->handle( $bot->id(), $destination->id(), $suffix, $message );
	}
}
