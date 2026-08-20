<?php
/**
 * Fixed-grammar message template rendering.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Automations;

use UniversalTelegram\Events\EventEnvelope;

/**
 * Replaces every {{ field.path }} token where field.path is a member of
 * the allowed-fields list with that field's value from the event,
 * MarkdownV2-escaped for Telegram. A token referencing a disallowed or
 * missing field renders as an empty string — never a PHP notice, never the
 * raw unescaped value, never a fatal. No conditionals, loops, or function
 * calls exist in this grammar (M02 plan §7.4).
 */
final class TemplateRenderer {

	private const TOKEN_PATTERN = '/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/';

	/**
	 * Telegram's own MarkdownV2 special-character set requiring escaping.
	 *
	 * @var array<int, string>
	 */
	private const ESCAPE_CHARS = array( '_', '*', '[', ']', '(', ')', '~', '`', '>', '#', '+', '-', '=', '|', '{', '}', '.', '!', '\\' );

	/**
	 * Renders a template against one event occurrence.
	 *
	 * @param string             $template       The message template.
	 * @param EventEnvelope      $event          The event occurrence.
	 * @param array<int, string> $allowed_fields The event type's own allowed variable fields.
	 *
	 * @return string
	 */
	public function render( string $template, EventEnvelope $event, array $allowed_fields ): string {
		$result = preg_replace_callback(
			self::TOKEN_PATTERN,
			function ( array $matches ) use ( $event, $allowed_fields ): string {
				return $this->resolve_token( $matches[1], $event, $allowed_fields );
			},
			$template
		);

		return null === $result ? '' : $result;
	}

	/**
	 * Resolves one {{ field.path }} token.
	 *
	 * @param string             $field          The dot-notation field path.
	 * @param EventEnvelope      $event          The event occurrence.
	 * @param array<int, string> $allowed_fields The event type's own allowed variable fields.
	 *
	 * @return string
	 */
	private function resolve_token( string $field, EventEnvelope $event, array $allowed_fields ): string {
		if ( ! in_array( $field, $allowed_fields, true ) ) {
			return '';
		}

		$value = $event->value_at( $field );

		if ( null === $value || is_array( $value ) ) {
			return '';
		}

		if ( is_bool( $value ) ) {
			$value = $value ? 'true' : 'false';
		}

		return $this->escape_markdown_v2( (string) $value );
	}

	/**
	 * Escapes Telegram's fixed MarkdownV2 special-character set.
	 *
	 * @param string $value The raw value.
	 *
	 * @return string
	 */
	private function escape_markdown_v2( string $value ): string {
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
