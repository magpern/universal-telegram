<?php
/**
 * Entity-based Telegram bot-command recognition.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Telegram\Commands;

/**
 * Recognizes a Telegram bot command from a decoded `message` payload
 * (M08, ADR-0027 decision 1): a message is a command only if it carries a
 * `bot_command` entity at offset 0, the covered word is one of
 * CommandCatalogue's sixteen allow-listed literals, and — when an
 * `@username` suffix is present — that suffix case-insensitively matches
 * the receiving bot's own already-persisted `telegram_username`. Anything
 * else (no entity, wrong offset, unknown word, or addressed to a different
 * bot) is not a command at all: the caller falls through to existing
 * non-command handling unchanged. Purely static — no instance state,
 * matching this codebase's own `ConversationStatus`/`ConversationDisplay`
 * precedent for a small, dependency-free parsing/policy class.
 */
final class CommandParser {

	/**
	 * @param array<string, mixed> $message      The decoded `message` payload (the same array
	 *                                            WebhookController already extracts text/sender from).
	 * @param string|null          $bot_username  The receiving bot's own BotProfile::telegram_username(),
	 *                                            or null if never populated.
	 *
	 * @return ParsedCommand|null Null when the message is not a recognized command at all.
	 */
	public static function parse( array $message, ?string $bot_username ): ?ParsedCommand {
		if ( ! isset( $message['text'] ) || ! is_string( $message['text'] ) ) {
			return null;
		}

		if ( ! isset( $message['entities'] ) || ! is_array( $message['entities'] ) ) {
			return null;
		}

		$entity = self::bot_command_entity_at_offset_zero( $message['entities'] );

		if ( null === $entity ) {
			return null;
		}

		$text  = $message['text'];
		$token = substr( $text, 0, $entity['length'] );

		if ( '' === $token || '/' !== $token[0] ) {
			return null;
		}

		$word = substr( $token, 1 );

		if ( '' === $word ) {
			return null;
		}

		$at_position = strpos( $word, '@' );
		$command     = strtolower( false === $at_position ? $word : substr( $word, 0, $at_position ) );
		$suffix      = false === $at_position ? null : substr( $word, $at_position + 1 );

		if ( ! CommandCatalogue::is_known( $command ) ) {
			return null;
		}

		if ( null !== $suffix && ( null === $bot_username || 0 !== strcasecmp( $suffix, $bot_username ) ) ) {
			// Addressed to a different bot — not a command for us.
			return null;
		}

		$remainder = trim( substr( $text, $entity['length'] ) );

		return new ParsedCommand( $command, $remainder, CommandCatalogue::is_argument_valid( $command, $remainder ) );
	}

	/**
	 * The first `bot_command` entity at offset 0, if any.
	 *
	 * @param array<int, mixed> $entities The message's `entities` array.
	 *
	 * @return array{type: string, offset: int, length: int}|null
	 */
	private static function bot_command_entity_at_offset_zero( array $entities ): ?array {
		foreach ( $entities as $entity ) {
			if ( ! is_array( $entity ) ) {
				continue;
			}

			if ( 'bot_command' === ( $entity['type'] ?? null )
				&& 0 === ( $entity['offset'] ?? null )
				&& isset( $entity['length'] )
				&& is_int( $entity['length'] )
				&& $entity['length'] > 0 ) {
				return $entity;
			}
		}

		return null;
	}
}
