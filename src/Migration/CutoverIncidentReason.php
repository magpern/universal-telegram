<?php
/**
 * Closed, non-content UT-only cutover incident reason vocabulary.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Migration;

/**
 * The five closed reason codes for a UT-only cutover incident (docs/adr/0042
 * §4): a pre-dispatch failure occurring entirely inside this plugin's own
 * `CutoverReplayDispatcher`, strictly before any Support Chat Contract call
 * is attempted, or a durable provenance-conflict refusal from Support Chat
 * itself. No Support Chat handoff-map row is ever written for any of these
 * — that is a structural, tested property, not a convention.
 */
final class CutoverIncidentReason {

	public const DECRYPT_FAILED = 'decrypt_failed';

	public const PARSE_FAILED = 'parse_failed';

	public const UNSUPPORTED_COMMAND = 'unsupported_command';

	public const UNMAPPED_SENDER = 'unmapped_sender';

	public const HANDOFF_PROVENANCE_CONFLICT = 'handoff_provenance_conflict';

	/**
	 * The closed set — used for defensive validation, e.g. rejecting an
	 * unrecognised reason at the point a caller would otherwise record one.
	 *
	 * @return array<int, string>
	 */
	public static function all(): array {
		return array(
			self::DECRYPT_FAILED,
			self::PARSE_FAILED,
			self::UNSUPPORTED_COMMAND,
			self::UNMAPPED_SENDER,
			self::HANDOFF_PROVENANCE_CONFLICT,
		);
	}
}
