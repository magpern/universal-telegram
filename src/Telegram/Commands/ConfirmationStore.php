<?php
/**
 * Short-lived confirmation state for `/resolve` and `/reopen`.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Telegram\Commands;

/**
 * A thin wrapper over WordPress core transients (M08, ADR-0027 decision
 * 5) — the same mechanism and 60-second TTL
 * `Administration\Diagnostics\DiagnosticsPage` already uses for other
 * short-lived, non-critical cached state. Keyed deterministically on
 * `(bot_id, conversation_id, wp_user_id)`, so `/confirm` can only match a
 * pending entry when the same mapped operator sends it in the same
 * conversation topic on the same bot — enforced by the key composition
 * itself, not a separate check. The stored value is the single pending
 * command literal (`resolve` or `reopen`) — nothing else, never a Telegram
 * id or message content. No schema, no new table.
 */
final class ConfirmationStore {

	private const TTL_SECONDS = 60;

	/**
	 * Records a pending confirmation, expiring automatically after 60
	 * seconds via WordPress core's own transient expiry.
	 *
	 * @param int    $bot_id          The bot's primary key.
	 * @param int    $conversation_id The conversation's primary key.
	 * @param int    $wp_user_id      The requesting operator's WordPress user id.
	 * @param string $command         'resolve' or 'reopen'.
	 */
	public function request( int $bot_id, int $conversation_id, int $wp_user_id, string $command ): void {
		set_transient( $this->key( $bot_id, $conversation_id, $wp_user_id ), $command, self::TTL_SECONDS );
	}

	/**
	 * Consumes a pending confirmation exactly once: reads it, then
	 * immediately deletes it, so a second call (duplicate, race, or after
	 * expiry) always returns null — the identical, non-disclosing outcome
	 * whether the entry never existed, already expired, or was already
	 * consumed.
	 *
	 * @param int $bot_id          The bot's primary key.
	 * @param int $conversation_id The conversation's primary key.
	 * @param int $wp_user_id      The confirming operator's WordPress user id.
	 *
	 * @return string|null 'resolve', 'reopen', or null if nothing matched.
	 */
	public function consume( int $bot_id, int $conversation_id, int $wp_user_id ): ?string {
		$key   = $this->key( $bot_id, $conversation_id, $wp_user_id );
		$value = get_transient( $key );

		if ( false === $value ) {
			return null;
		}

		delete_transient( $key );

		return (string) $value;
	}

	/**
	 * Builds the deterministic transient key for one pending confirmation.
	 *
	 * @param int $bot_id          The bot's primary key.
	 * @param int $conversation_id The conversation's primary key.
	 * @param int $wp_user_id      The operator's WordPress user id.
	 *
	 * @return string
	 */
	private function key( int $bot_id, int $conversation_id, int $wp_user_id ): string {
		return "ut_telegram_cmd_confirm_{$bot_id}_{$conversation_id}_{$wp_user_id}";
	}
}
