<?php
/**
 * Native Telegram reply → support-ticket customer reply.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Integrations\FluentContactInbox\Inbound;

use UniversalTelegram\Integrations\FluentContactInbox\SupportDeskGateway;
use UniversalTelegram\Telegram\Inbound\CorrelatedReplyHandler;

/**
 * Handles a native reply to a `ticket:{id}` notification (docs/adr/0046).
 * Order: operator identity + capability → rate limit → ticket reference →
 * ticket state → text → the desk's canonical reply operation. Success is
 * confirmed in chat only after the desk reports success; every rejection is
 * answered in chat and the update is claimed. Runs strictly after the
 * webhook's `(bot_id, update_id)` dedup guard, so duplicate delivery of one
 * update can never send two customer emails.
 */
final class TicketReplyHandler implements CorrelatedReplyHandler {

	public const TOKEN_PREFIX = 'ticket';

	public const MSG_UNAUTHORIZED  = 'You are not authorized to reply to support tickets from Telegram.';
	public const MSG_RATE_LIMITED  = 'Too many replies in a short time. Please wait a moment and try again.';
	public const MSG_BAD_REFERENCE = 'This notification does not reference a valid support ticket.';
	public const MSG_NO_TICKET     = 'This ticket no longer exists, so the reply was not sent.';
	public const MSG_NO_EMAIL      = 'This ticket has no customer email address on file, so the reply was not sent.';
	public const MSG_ARCHIVED      = 'This ticket is archived. Unarchive it in WP admin before replying.';
	public const MSG_NOT_TEXT      = 'Please reply with plain text. Photos, files and stickers cannot be sent as a ticket reply.';
	public const MSG_SEND_FAILED   = 'The reply could not be sent';

	/**
	 * Constructor.
	 *
	 * @param SupportDeskGateway     $desk        The support desk.
	 * @param TicketReplyEnvironment $environment Telegram-side services.
	 */
	public function __construct(
		private readonly SupportDeskGateway $desk,
		private readonly TicketReplyEnvironment $environment
	) {}

	/**
	 * {@inheritDoc}
	 *
	 * @param int                  $bot_id         The receiving bot's primary key.
	 * @param int                  $destination_id The destination the notification was delivered to.
	 * @param string               $token_suffix   The ticket id.
	 * @param array<string, mixed> $message        The decoded Telegram `message` object.
	 */
	public function handle( int $bot_id, int $destination_id, string $token_suffix, array $message ): bool {
		$sender_id = $message['from']['id'] ?? null;

		if ( ! is_int( $sender_id ) ) {
			return true;
		}

		$wp_user_id = $this->environment->authorized_operator_wp_user_id( $sender_id );

		if ( null === $wp_user_id ) {
			$this->environment->record_rejection( 'unauthorized', $bot_id );
			$this->environment->respond( $bot_id, $destination_id, self::MSG_UNAUTHORIZED );

			return true;
		}

		if ( ! $this->environment->within_rate_limit( $wp_user_id ) ) {
			$this->environment->respond( $bot_id, $destination_id, self::MSG_RATE_LIMITED );

			return true;
		}

		if ( ! ctype_digit( $token_suffix ) || (int) $token_suffix <= 0 ) {
			$this->environment->respond( $bot_id, $destination_id, self::MSG_BAD_REFERENCE );

			return true;
		}

		$ticket = $this->desk->find_ticket( (int) $token_suffix );

		if ( null === $ticket ) {
			$this->environment->respond( $bot_id, $destination_id, self::MSG_NO_TICKET );

			return true;
		}

		if ( false === filter_var( $ticket->customer_email, FILTER_VALIDATE_EMAIL ) ) {
			$this->environment->respond( $bot_id, $destination_id, self::MSG_NO_EMAIL );

			return true;
		}

		if ( $ticket->archived ) {
			$this->environment->respond( $bot_id, $destination_id, self::MSG_ARCHIVED );

			return true;
		}

		$text = $message['text'] ?? null;

		if ( ! is_string( $text ) || '' === trim( $text ) ) {
			$this->environment->respond( $bot_id, $destination_id, self::MSG_NOT_TEXT );

			return true;
		}

		$error = $this->desk->send_reply( $ticket, $text, $wp_user_id );

		if ( null !== $error ) {
			$this->environment->respond( $bot_id, $destination_id, self::MSG_SEND_FAILED . ': ' . $error );

			return true;
		}

		$this->environment->respond(
			$bot_id,
			$destination_id,
			sprintf( 'Reply sent to %s on ticket #%d', $ticket->customer_email, $ticket->ticket_number )
		);

		return true;
	}
}
