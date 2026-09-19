<?php
/**
 * Production digest sender.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Integrations\FluentContactInbox\Digest;

use UniversalTelegram\Telegram\Configuration\DestinationEligibility;
use UniversalTelegram\Telegram\Outbound\MessageDispatcher;

/**
 * Re-validates bot/destination eligibility at send time (the same shared rule
 * the M11 alerts use) and hands off to the one outbound path. No correlation
 * token: a digest is not a single-ticket reply target.
 */
final class MessageDispatcherDigestSender implements DigestSender {

	/**
	 * Constructor.
	 *
	 * @param DestinationEligibility $eligibility Shared eligibility rule.
	 * @param MessageDispatcher      $dispatcher  The sole outbound path.
	 */
	public function __construct(
		private readonly DestinationEligibility $eligibility,
		private readonly MessageDispatcher $dispatcher
	) {}

	/**
	 * {@inheritDoc}
	 *
	 * @param int    $bot_id         The bot's primary key.
	 * @param int    $destination_id The destination's primary key.
	 * @param string $text           MarkdownV2-escaped text.
	 */
	public function send( int $bot_id, int $destination_id, string $text ): bool {
		if ( ! $this->eligibility->destination_is_eligible( $bot_id, $destination_id ) ) {
			return false;
		}

		return null !== $this->dispatcher->send( $bot_id, $destination_id, $text, 'MarkdownV2' );
	}
}
