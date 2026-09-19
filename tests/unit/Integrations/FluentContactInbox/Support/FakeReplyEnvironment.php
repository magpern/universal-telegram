<?php
/**
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Tests\Integrations\FluentContactInbox\Support;

use UniversalTelegram\Integrations\FluentContactInbox\Inbound\TicketReplyEnvironment;

/**
 * Recording Telegram-side environment for handler tests.
 */
final class FakeReplyEnvironment implements TicketReplyEnvironment {

	/**
	 * Mapped operators: Telegram user id => WP user id.
	 *
	 * @var array<int, int>
	 */
	public array $operators = array( 555 => 7 );

	/**
	 * Whether the rate limiter allows the operator.
	 *
	 * @var bool
	 */
	public bool $allow = true;

	/**
	 * Answers sent in chat.
	 *
	 * @var array<int, string>
	 */
	public array $responses = array();

	/**
	 * Recorded rejection reasons.
	 *
	 * @var array<int, string>
	 */
	public array $rejections = array();

	/**
	 * {@inheritDoc}
	 *
	 * @param int $telegram_user_id Sender id.
	 */
	public function authorized_operator_wp_user_id( int $telegram_user_id ): ?int {
		return $this->operators[ $telegram_user_id ] ?? null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $wp_user_id Operator.
	 */
	public function within_rate_limit( int $wp_user_id ): bool {
		return $this->allow;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int    $bot_id         Bot.
	 * @param int    $destination_id Destination.
	 * @param string $text           Text.
	 */
	public function respond( int $bot_id, int $destination_id, string $text ): void {
		$this->responses[] = $text;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $reason Reason.
	 * @param int    $bot_id Bot.
	 */
	public function record_rejection( string $reason, int $bot_id ): void {
		$this->rejections[] = $reason;
	}
}
