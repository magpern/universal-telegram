<?php
/**
 * Fixed, allow-listed administrative-bot command catalogue.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Telegram\Commands;

/**
 * The complete, frozen set of sixteen Telegram bot commands this plugin
 * recognizes (M08, ADR-0027), their valid dispatch context(s), and their
 * argument shape. Purely static, matching this codebase's own
 * `ConversationStatus` precedent for a small, fixed, dependency-free
 * policy table — no instance, no constructor, no injected state.
 */
final class CommandCatalogue {

	/** General topic (or a group with no forum topics) only. */
	public const CONTEXT_GENERAL = 'general';

	/** A conversation topic resolved via ConversationRepository::find_by_topic() only. */
	public const CONTEXT_CONVERSATION = 'conversation';

	/** Either context. */
	public const CONTEXT_ANY = 'any';

	/** No argument accepted; any non-empty trailing text is malformed. */
	public const ARGUMENT_NONE = 'none';

	/** A bounded numeric id, 1-20 digits. */
	public const ARGUMENT_NUMERIC_ID = 'numeric_id';

	/** A bounded token, 1-100 chars, no `%` or `*` wildcard characters. */
	public const ARGUMENT_TOKEN = 'token';

	/** One of a fixed literal set. */
	public const ARGUMENT_LITERAL = 'literal';

	private const MAX_TOKEN_CHARS = 100;

	/**
	 * command => [context, argument_kind, allowed literals (for ARGUMENT_LITERAL only)].
	 *
	 * @return array<string, array{0: string, 1: string, 2: array<int, string>}>
	 */
	private static function definitions(): array {
		return array(
			'help'          => array( self::CONTEXT_ANY, self::ARGUMENT_NONE, array() ),
			'whoami'        => array( self::CONTEXT_ANY, self::ARGUMENT_NONE, array() ),
			'status'        => array( self::CONTEXT_GENERAL, self::ARGUMENT_NONE, array() ),
			'errors'        => array( self::CONTEXT_GENERAL, self::ARGUMENT_NONE, array() ),
			'visitors'      => array( self::CONTEXT_GENERAL, self::ARGUMENT_NONE, array() ),
			'orders'        => array( self::CONTEXT_GENERAL, self::ARGUMENT_NONE, array() ),
			'order'         => array( self::CONTEXT_GENERAL, self::ARGUMENT_NUMERIC_ID, array() ),
			'stock'         => array( self::CONTEXT_GENERAL, self::ARGUMENT_TOKEN, array() ),
			'sales'         => array( self::CONTEXT_GENERAL, self::ARGUMENT_LITERAL, array( 'today', 'week', 'month' ) ),
			'conversations' => array( self::CONTEXT_GENERAL, self::ARGUMENT_NONE, array() ),
			'here'          => array( self::CONTEXT_CONVERSATION, self::ARGUMENT_NONE, array() ),
			'presence'      => array( self::CONTEXT_GENERAL, self::ARGUMENT_LITERAL, array( 'available', 'busy', 'offline' ) ),
			'claim'         => array( self::CONTEXT_CONVERSATION, self::ARGUMENT_NONE, array() ),
			'release'       => array( self::CONTEXT_CONVERSATION, self::ARGUMENT_NONE, array() ),
			'resolve'       => array( self::CONTEXT_CONVERSATION, self::ARGUMENT_NONE, array() ),
			'reopen'        => array( self::CONTEXT_CONVERSATION, self::ARGUMENT_NONE, array() ),
			'confirm'       => array( self::CONTEXT_CONVERSATION, self::ARGUMENT_NONE, array() ),
		);
	}

	/**
	 * Whether a lowercase command word (without the leading `/`) is one of
	 * the sixteen allow-listed commands.
	 *
	 * @param string $command Lowercase command word, no leading slash.
	 *
	 * @return bool
	 */
	public static function is_known( string $command ): bool {
		return isset( self::definitions()[ $command ] );
	}

	/**
	 * The context a command requires: CONTEXT_GENERAL, CONTEXT_CONVERSATION,
	 * or CONTEXT_ANY. Returns null for an unknown command.
	 *
	 * @param string $command Lowercase command word, no leading slash.
	 *
	 * @return string|null
	 */
	public static function context_for( string $command ): ?string {
		return self::definitions()[ $command ][0] ?? null;
	}

	/**
	 * Whether a raw, already-trimmed argument string satisfies a known
	 * command's own fixed argument shape. An empty string represents "no
	 * argument supplied".
	 *
	 * @param string $command       Lowercase command word, no leading slash.
	 * @param string $raw_argument  The trimmed text following the command token.
	 *
	 * @return bool
	 */
	public static function is_argument_valid( string $command, string $raw_argument ): bool {
		$definition = self::definitions()[ $command ] ?? null;

		if ( null === $definition ) {
			return false;
		}

		list( , $kind, $literals ) = $definition;

		switch ( $kind ) {
			case self::ARGUMENT_NONE:
				return '' === $raw_argument;
			case self::ARGUMENT_NUMERIC_ID:
				return 1 === preg_match( '/^[0-9]{1,20}$/', $raw_argument );
			case self::ARGUMENT_TOKEN:
				return '' !== $raw_argument
					&& self::MAX_TOKEN_CHARS >= strlen( $raw_argument )
					&& false === strpbrk( $raw_argument, '%*' );
			case self::ARGUMENT_LITERAL:
				return in_array( $raw_argument, $literals, true );
			default:
				return false;
		}
	}

	/**
	 * Every known command word, in a fixed, stable order — used to render
	 * `/help`.
	 *
	 * @return array<int, string>
	 */
	public static function all_commands(): array {
		return array_keys( self::definitions() );
	}

	/**
	 * Every known command word valid in a given context (CONTEXT_GENERAL or
	 * CONTEXT_CONVERSATION), including CONTEXT_ANY commands — used to
	 * render `/help`.
	 *
	 * @param string $context CONTEXT_GENERAL or CONTEXT_CONVERSATION.
	 *
	 * @return array<int, string>
	 */
	public static function commands_valid_in( string $context ): array {
		$matching = array();

		foreach ( self::definitions() as $command => $definition ) {
			if ( self::CONTEXT_ANY === $definition[0] || $context === $definition[0] ) {
				$matching[] = $command;
			}
		}

		return $matching;
	}
}
