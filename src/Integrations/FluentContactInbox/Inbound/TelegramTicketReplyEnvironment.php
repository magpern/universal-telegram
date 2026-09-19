<?php
/**
 * Production adapter for the ticket-reply handler's environment.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Integrations\FluentContactInbox\Inbound;

use UniversalTelegram\Audit\AuditLogger;
use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;
use UniversalTelegram\Privacy\Classification;
use UniversalTelegram\SupportChatAdapter\Identity\OperatorIdentityMapRepository;
use UniversalTelegram\Telegram\Outbound\MessageDispatcher;
use UniversalTelegram\Telegram\Reliability\RateLimiter;

/**
 * Wraps the same identity map + capability check the bot-command and
 * callback dispatchers use (docs/adr/0027), the existing token-bucket
 * RateLimiter, and the one outbound path.
 */
final class TelegramTicketReplyEnvironment implements TicketReplyEnvironment {

	private const RATE_SCOPE    = 'ticket_reply';
	private const RATE_CAPACITY = 5.0;
	private const RATE_REFILL   = 0.1;

	/**
	 * Constructor.
	 *
	 * @param OperatorIdentityMapRepository $operator_identities Resolves the sender's mapped operator.
	 * @param RateLimiter                   $rate_limiter        Per-operator token bucket.
	 * @param MessageDispatcher             $message_dispatcher  The sole outbound path.
	 * @param AuditLogger                   $audit               Records rejections.
	 */
	public function __construct(
		private readonly OperatorIdentityMapRepository $operator_identities,
		private readonly RateLimiter $rate_limiter,
		private readonly MessageDispatcher $message_dispatcher,
		private readonly AuditLogger $audit
	) {}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $telegram_user_id The sender's numeric Telegram id.
	 */
	public function authorized_operator_wp_user_id( int $telegram_user_id ): ?int {
		$identity = $this->operator_identities->find_by_telegram_user_id( $telegram_user_id );

		if ( null === $identity || ! user_can( $identity->wp_user_id(), CapabilityRegistrar::MANAGE_CONVERSATIONS ) ) {
			return null;
		}

		return $identity->wp_user_id();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $wp_user_id The operator's WordPress user id.
	 */
	public function within_rate_limit( int $wp_user_id ): bool {
		return $this->rate_limiter->try_consume( self::RATE_SCOPE, $wp_user_id, self::RATE_CAPACITY, self::RATE_REFILL );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int    $bot_id         The bot's primary key.
	 * @param int    $destination_id The destination's primary key.
	 * @param string $text           The plain-text answer.
	 */
	public function respond( int $bot_id, int $destination_id, string $text ): void {
		$this->message_dispatcher->send( $bot_id, $destination_id, $text, null, null, true );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $reason A fixed, non-sensitive reason code.
	 * @param int    $bot_id The receiving bot's primary key.
	 */
	public function record_rejection( string $reason, int $bot_id ): void {
		$this->audit->record(
			'ticket_reply.rejected_' . $reason,
			'system',
			null,
			array( 'bot_id' => $bot_id ),
			array( 'bot_id' => Classification::INTERNAL ),
			Classification::INTERNAL
		);
	}
}
