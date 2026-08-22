<?php
/**
 * Visitor display-name validation and Telegram-facing formatting.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Conversations;

/**
 * The sole owner of the UTF-8-safe bound used both to validate a visitor
 * display name and to truncate it into a Telegram topic title (M06.3,
 * ADR-0024) — one shared helper so the two call sites can never drift.
 * Built on mb_strlen()/mb_substr(), the pattern already established
 * elsewhere in this boundary (Rest\ConversationsController's own message-
 * text bound); no new extension dependency is introduced.
 */
final class ConversationDisplay {

	public const MIN_DISPLAY_NAME_CHARS = 1;
	public const MAX_DISPLAY_NAME_CHARS = 80;

	/**
	 * Telegram's own forum-topic name length cap.
	 */
	private const MAX_TOPIC_TITLE_CHARS = 128;

	/**
	 * Number of leading characters of a conversation_uuid used as the
	 * public, non-secret short reference appended to a topic title and the
	 * first-message context header. Plain substr() is correct here — a
	 * UUID is pure ASCII hex, never multibyte.
	 */
	private const SHORT_REF_CHARS = 8;

	/**
	 * Whether a trimmed candidate display name satisfies the 1-80 UTF-8
	 * character bound (M06.3, ADR-0024). Callers must trim() before
	 * calling this.
	 *
	 * @param string $trimmed_value The already-trimmed candidate value.
	 *
	 * @return bool
	 */
	public static function is_valid_display_name( string $trimmed_value ): bool {
		$length = mb_strlen( $trimmed_value, 'UTF-8' );

		return $length >= self::MIN_DISPLAY_NAME_CHARS && $length <= self::MAX_DISPLAY_NAME_CHARS;
	}

	/**
	 * UTF-8-safe truncation to at most $max_chars characters. Never splits
	 * a multibyte character, unlike byte-oriented substr().
	 *
	 * @param string $value     The value to bound.
	 * @param int    $max_chars The maximum number of UTF-8 characters to keep.
	 *
	 * @return string
	 */
	public static function bounded_utf8( string $value, int $max_chars ): string {
		if ( $max_chars <= 0 ) {
			return '';
		}

		return mb_substr( $value, 0, $max_chars, 'UTF-8' );
	}

	/**
	 * The public, non-secret short reference for a conversation — the
	 * UUID's own first 8 hex characters, already the public lookup key
	 * (never the bearer secret, never the internal numeric id).
	 *
	 * @param string $conversation_uuid The conversation's own public identifier.
	 *
	 * @return string
	 */
	public static function short_ref( string $conversation_uuid ): string {
		return substr( $conversation_uuid, 0, self::SHORT_REF_CHARS );
	}

	/**
	 * The Telegram forum-topic title for a conversation (M06.3, ADR-0024):
	 * a UTF-8-safe truncated display name plus the short reference, bounded
	 * to Telegram's 128-character cap with the suffix's own length reserved
	 * first — or, when no display name is stored (a pre-M06.3 conversation,
	 * or one whose topic is created before a name is ever supplied), the
	 * pre-M06.3 literal unchanged.
	 *
	 * @param string|null $display_name      The decrypted display name, or null if none is stored.
	 * @param string      $conversation_uuid The conversation's own public identifier.
	 *
	 * @return string
	 */
	public static function topic_title( ?string $display_name, string $conversation_uuid ): string {
		if ( null === $display_name || '' === $display_name ) {
			return 'Conversation ' . $conversation_uuid;
		}

		$short_ref = self::short_ref( $conversation_uuid );
		$suffix    = ' · ' . $short_ref;
		$reserved  = mb_strlen( $suffix, 'UTF-8' );

		return self::bounded_utf8( $display_name, self::MAX_TOPIC_TITLE_CHARS - $reserved ) . $suffix;
	}

	/**
	 * The one-line, non-secret context header prepended to the first
	 * forwarded visitor message only (M06.3, ADR-0024) — the display name
	 * and short reference only, never the bearer secret, the internal
	 * numeric conversation id, raw ciphertext, or visitor IP.
	 *
	 * @param string $display_name      The decrypted display name.
	 * @param string $conversation_uuid The conversation's own public identifier.
	 *
	 * @return string
	 */
	public static function first_message_context_header( string $display_name, string $conversation_uuid ): string {
		return '[' . $display_name . ' · ' . self::short_ref( $conversation_uuid ) . ']';
	}
}
