<?php
/**
 * Telegram MarkdownV2 escaping helper.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Automations;

/**
 * Escapes Telegram's fixed MarkdownV2 special-character set. Shared by
 * TemplateRenderer's token substitution and the fixed-grammar digest
 * renderers whose timestamps and punctuation would otherwise trip parse
 * mode (M11A plan §6).
 */
final class MarkdownV2Escaper {

	/**
	 * Telegram's own MarkdownV2 special-character set requiring escaping.
	 *
	 * @var array<int, string>
	 */
	private const ESCAPE_CHARS = array( '_', '*', '[', ']', '(', ')', '~', '`', '>', '#', '+', '-', '=', '|', '{', '}', '.', '!', '\\' );

	/**
	 * Escapes one value for Telegram MarkdownV2 parse mode.
	 *
	 * @param string $value The raw value.
	 *
	 * @return string
	 */
	public static function escape( string $value ): string {
		$escaped = '';

		foreach ( mb_str_split( $value ) as $char ) {
			if ( in_array( $char, self::ESCAPE_CHARS, true ) ) {
				$escaped .= '\\' . $char;
			} else {
				$escaped .= $char;
			}
		}

		return $escaped;
	}
}
