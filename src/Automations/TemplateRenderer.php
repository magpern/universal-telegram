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
 * raw unescaped value, never a fatal. The template's own literal text is
 * MarkdownV2-escaped too, so an admin-authored template is free-form text,
 * never markup an admin must hand-escape themselves. No conditionals,
 * loops, or function calls exist in this grammar (M02 plan §7.4).
 */
final class TemplateRenderer {

	private const TOKEN_PATTERN = '/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/';

	/**
	 * Renders a template against one event occurrence. The template's own
	 * literal text (everything outside {{ field.path }} tokens) is
	 * MarkdownV2-escaped exactly like a resolved token's value — an
	 * admin-authored template is free-form text, not markup, so a literal
	 * `.`, `#`, `(`, `)`, etc. must reach Telegram escaped or every message
	 * containing one is rejected outright and dead-lettered.
	 *
	 * @param string             $template       The message template.
	 * @param EventEnvelope      $event          The event occurrence.
	 * @param array<int, string> $allowed_fields The event type's own allowed variable fields.
	 *
	 * @return string
	 */
	public function render( string $template, EventEnvelope $event, array $allowed_fields ): string {
		$parts = preg_split( self::TOKEN_PATTERN, $template, -1, PREG_SPLIT_DELIM_CAPTURE );

		if ( false === $parts ) {
			return '';
		}

		$result = '';

		foreach ( $parts as $index => $part ) {
			// preg_split with PREG_SPLIT_DELIM_CAPTURE alternates literal
			// text (even indices) with the captured token field name (odd
			// indices).
			$result .= 0 === $index % 2
				? $this->escape_markdown_v2( $part )
				: $this->resolve_token( $part, $event, $allowed_fields );
		}

		return $result;
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

		return self::escape_markdown_v2( (string) $value );
	}

	/**
	 * Escapes Telegram's fixed MarkdownV2 special-character set.
	 *
	 * @param string $value The raw value.
	 *
	 * @return string
	 */
	private function escape_markdown_v2( string $value ): string {
		return MarkdownV2Escaper::escape( $value );
	}
}
