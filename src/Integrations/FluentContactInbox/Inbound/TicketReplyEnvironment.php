<?php
/**
 * Port for the Telegram-side services the ticket-reply handler needs.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Integrations\FluentContactInbox\Inbound;

/**
 * Production adapter: {@see TelegramTicketReplyEnvironment}; tests inject a
 * fake.
 */
interface TicketReplyEnvironment {

	/**
	 * The WordPress user id of a Telegram user mapped as an operator who
	 * holds `CapabilityRegistrar::MANAGE_CONVERSATIONS`, else null.
	 *
	 * @param int $telegram_user_id The sender's numeric Telegram id.
	 *
	 * @return int|null
	 */
	public function authorized_operator_wp_user_id( int $telegram_user_id ): ?int;

	/**
	 * Consumes one ticket-reply token for the operator; false when the
	 * operator is over the limit.
	 *
	 * @param int $wp_user_id The operator's WordPress user id.
	 *
	 * @return bool
	 */
	public function within_rate_limit( int $wp_user_id ): bool;

	/**
	 * Answers in the destination the reply came from (plain text, interactive).
	 *
	 * @param int    $bot_id         The bot's primary key.
	 * @param int    $destination_id The destination's primary key.
	 * @param string $text           The plain-text answer.
	 */
	public function respond( int $bot_id, int $destination_id, string $text ): void;

	/**
	 * Records a rejected reply attempt in the audit log.
	 *
	 * @param string $reason A fixed, non-sensitive reason code.
	 * @param int    $bot_id The receiving bot's primary key.
	 */
	public function record_rejection( string $reason, int $bot_id ): void;
}
