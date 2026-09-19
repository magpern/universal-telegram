<?php
/**
 * Pure composition of the customer-facing reply.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Integrations\FluentContactInbox;

/**
 * Turns a manager's plain Telegram text into the HTML body and subject the
 * support desk's reply operation expects. The desk itself adds greeting,
 * signature, quoting and the ticket reference tag.
 */
final class ReplyComposer {

	/**
	 * Escaped HTML body preserving line breaks.
	 *
	 * @param string $text Plain text.
	 *
	 * @return string
	 */
	public static function html_body( string $text ): string {
		return nl2br( htmlspecialchars( trim( $text ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ), false );
	}

	/**
	 * Reply subject as the WP-admin reply form pre-fills it (`Re: …`), or an
	 * empty string so the desk applies its own default.
	 *
	 * @param string $ticket_subject The ticket's subject.
	 *
	 * @return string
	 */
	public static function subject( string $ticket_subject ): string {
		$subject = trim( $ticket_subject );

		return '' === $subject ? '' : 'Re: ' . $subject;
	}
}
