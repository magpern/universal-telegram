<?php
/**
 * Fixed Telegram topic-error allow-list matching.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Telegram\Client;

/**
 * Matches Telegram API description text against a fixed allow-list, then
 * discards the raw string. Callers must never persist or audit the input
 * description (M07.1, docs/adr/0031).
 */
final class TelegramTopicError {

	public const TOPIC_NOT_FOUND                 = 'telegram_topic_not_found';
	public const TOPIC_CLOSED                    = 'telegram_topic_closed';
	public const TOPIC_DELETE_FORBIDDEN          = 'telegram_topic_delete_forbidden';
	public const TOPIC_DELETE_CHAT_NOT_FOUND     = 'telegram_topic_delete_chat_not_found';
	public const TOPIC_DELETE_ATTEMPTS_EXHAUSTED = 'telegram_topic_delete_attempts_exhausted';
	public const TERMINAL_REJECTION              = 'telegram_terminal_rejection';

	/**
	 * Classifies a failed sendMessage description for a destination that
	 * already carries message_thread_id > 1. Returns a fixed code; never
	 * returns the raw description.
	 *
	 * @param string|null $description Telegram's description field.
	 *
	 * @return string One of the TOPIC_* / TERMINAL_REJECTION constants.
	 */
	public static function classify_send_failure( ?string $description ): string {
		$normalized = self::normalize( $description );

		if ( self::matches_any( $normalized, array( 'message thread not found', 'topic not found', 'topic_id_invalid' ) ) ) {
			return self::TOPIC_NOT_FOUND;
		}

		if ( self::matches_any( $normalized, array( 'topic_closed', 'topic is closed', 'forum topic is closed' ) ) ) {
			return self::TOPIC_CLOSED;
		}

		return self::TERMINAL_REJECTION;
	}

	/**
	 * Classifies a failed deleteForumTopic response into a fixed outcome code.
	 *
	 * @param TelegramApiResult $result A failed API result.
	 *
	 * @return string Fixed code: success paths are not classified here.
	 */
	public static function classify_delete_failure( TelegramApiResult $result ): string {
		$status     = $result->http_status();
		$normalized = self::normalize( $result->description() );

		if ( 401 === $status ) {
			return 'telegram_token_invalid';
		}

		if ( 403 === $status || self::matches_any( $normalized, array( 'not enough rights', 'chat_admin_required', 'have no rights' ) ) ) {
			return self::TOPIC_DELETE_FORBIDDEN;
		}

		if ( self::matches_any( $normalized, array( 'chat not found' ) ) ) {
			return self::TOPIC_DELETE_CHAT_NOT_FOUND;
		}

		if ( self::matches_any( $normalized, array( 'message thread not found', 'topic not found', 'topic_id_invalid' ) ) ) {
			return self::TOPIC_NOT_FOUND;
		}

		return self::TERMINAL_REJECTION;
	}

	/**
	 * Whether a deleteForumTopic failure is an idempotent already-absent success.
	 *
	 * @param TelegramApiResult $result A failed API result.
	 *
	 * @return bool
	 */
	public static function is_missing_topic_on_delete( TelegramApiResult $result ): bool {
		if ( $result->ok() ) {
			return false;
		}

		return self::TOPIC_NOT_FOUND === self::classify_delete_failure( $result );
	}

	/**
	 * Normalizes a Telegram description for allow-list matching.
	 *
	 * @param string|null $description Raw description.
	 *
	 * @return string Lowercased trimmed description, or empty string.
	 */
	private static function normalize( ?string $description ): string {
		return null === $description ? '' : strtolower( trim( $description ) );
	}

	/**
	 * Whether the normalized description contains any allow-listed needle.
	 *
	 * @param string             $haystack Normalized description.
	 * @param array<int, string> $needles  Lowercase substrings.
	 *
	 * @return bool
	 */
	private static function matches_any( string $haystack, array $needles ): bool {
		if ( '' === $haystack ) {
			return false;
		}

		foreach ( $needles as $needle ) {
			if ( str_contains( $haystack, $needle ) ) {
				return true;
			}
		}

		return false;
	}
}
