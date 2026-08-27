<?php
/**
 * Closed, non-content UT-only cutover incident reason vocabulary.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Migration;

/**
 * The seven closed reason codes for a UT-only cutover incident (docs/adr/0042
 * §4, amended by docs/adr/0043 §3): a pre-dispatch failure occurring entirely
 * inside this plugin's own `CutoverReplayDispatcher`, strictly before any
 * Support Chat Contract call is attempted; a durable provenance-conflict
 * refusal from Support Chat; a Support Chat `404 not_found` after an active
 * binding was already selected (the `channel_case_ref`, now the Support Chat
 * conversation UUID, resolves to nothing — a data-integrity problem, not a
 * transient one); or any other deterministic Support Chat refusal, plus any
 * unrecognised `ok:false` reason (fail closed). No Support Chat handoff-map
 * row is ever written for any of these — that is a structural, tested
 * property, not a convention.
 */
final class CutoverIncidentReason {

	public const DECRYPT_FAILED = 'decrypt_failed';

	public const PARSE_FAILED = 'parse_failed';

	public const UNSUPPORTED_COMMAND = 'unsupported_command';

	public const UNMAPPED_SENDER = 'unmapped_sender';

	public const HANDOFF_PROVENANCE_CONFLICT = 'handoff_provenance_conflict';

	/**
	 * Support Chat returned `404 not_found` for a `channel_case_ref` after
	 * `CutoverReplayDispatcher`'s caller had already selected an active
	 * binding (docs/adr/0043 §3). Under the corrected wire `channel_case_ref`
	 * is the Support Chat conversation UUID, so this is a durable
	 * data-integrity condition, never an unbounded transient retry.
	 */
	public const UNRESOLVED_CASE_REFERENCE = 'unresolved_case_reference';

	/**
	 * Support Chat durably refused the handoff after an active binding was
	 * selected — an enumerated deterministic `400`/`409` refusal
	 * (`invalid_body`, `invalid_operator`, `unsupported_operation`,
	 * `already_claimed`, `claimed_by_other`, `invalid_transition`), the
	 * `sc_contract_unsupported_operation` client-side allow-list guard, or
	 * any unrecognised `ok:false` reason (fail closed). Retrying produces
	 * the identical refusal, so it is a classified incident, not retryable
	 * (docs/adr/0043 §3).
	 */
	public const HANDOFF_REJECTED = 'handoff_rejected';

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
			self::UNRESOLVED_CASE_REFERENCE,
			self::HANDOFF_REJECTED,
		);
	}
}
