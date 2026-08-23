<?php
/**
 * A recognized, allow-listed bot command.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Telegram\Commands;

/**
 * Immutable result of CommandParser::parse(): the recognized command word
 * (no leading slash, no `@username` suffix), the raw trimmed argument text
 * (empty string when none was supplied), and whether that argument
 * satisfies the command's own fixed shape (CommandCatalogue). A malformed
 * argument still yields a recognized command — the dispatcher renders a
 * distinct malformed-command acknowledgement rather than treating it as
 * "not a command at all".
 */
final class ParsedCommand {

	/**
	 * Constructor.
	 *
	 * @param string $command        Lowercase command word, no leading slash.
	 * @param string $raw_argument   Trimmed argument text; '' when none was supplied.
	 * @param bool   $argument_valid Whether $raw_argument satisfies CommandCatalogue's shape for $command.
	 */
	public function __construct(
		private readonly string $command,
		private readonly string $raw_argument,
		private readonly bool $argument_valid
	) {}

	/**
	 * Lowercase command word, no leading slash.
	 *
	 * @return string
	 */
	public function command(): string {
		return $this->command;
	}

	/**
	 * Trimmed argument text; '' when none was supplied.
	 *
	 * @return string
	 */
	public function raw_argument(): string {
		return $this->raw_argument;
	}

	/**
	 * Whether raw_argument() satisfies CommandCatalogue's shape for this command.
	 *
	 * @return bool
	 */
	public function is_argument_valid(): bool {
		return $this->argument_valid;
	}
}
