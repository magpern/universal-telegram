<?php
/**
 * Exhaustive classification of a failed Contract call during cohort-aware
 * deferred-update replay (docs/adr/0043 §3).
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Migration;

use UniversalTelegram\SupportChatAdapter\Inbound\SupportChatContractClient;

/**
 * Pure classifier for `CutoverReplayDispatcher::finish()`'s `ok:false`
 * branch, after the caller has already selected an **active** binding.
 * Every `(status, reason)` maps to exactly one of: `RETRYABLE` (a genuinely
 * transient condition — retried by the next ordinary replay pass, never an
 * incident) or one of the closed `CutoverIncidentReason` codes (a durable
 * UT-only incident that blocks `replaying → idle` / `confirm-complete`).
 *
 * There is **no** generic "everything else is transient" fallback: an
 * unrecognised `ok:false` reason fails closed to `handoff_rejected`
 * (docs/adr/0043 §3), so an unbounded silent retry that could block replay
 * forever without a classified outcome is structurally impossible.
 */
final class CutoverReplayFailureClassifier {

	/** The call failed transiently — retry on the next ordinary replay pass, never an incident. */
	public const RETRYABLE = 'retryable';

	/**
	 * The only `ok:false` Contract reasons that are genuinely transient
	 * (docs/adr/0043 §3): Support Chat's own transient DB-write failure
	 * (`request_failed`, HTTP 503) and signature/nonce/clock failure
	 * (`contract_auth_failed`, HTTP 401), plus this plugin's own client-side
	 * fail-closed gates (the request was never sent). `sc_contract_unsupported_operation`
	 * is deliberately excluded — a code-level allow-list violation, classified
	 * `handoff_rejected`.
	 */
	private const TRANSIENT_REASONS = array(
		'request_failed',
		'contract_auth_failed',
		SupportChatContractClient::UNAVAILABLE_REASON,
		SupportChatContractClient::REASON_NOT_PAIRED,
		SupportChatContractClient::REASON_DISCOVERY_INCOMPATIBLE,
		SupportChatContractClient::REASON_SIGNING_UNAVAILABLE,
		SupportChatContractClient::REASON_TRANSPORT_FAILED,
	);

	/**
	 * Classifies one failed (`ok:false`) Contract result.
	 *
	 * @param int         $status HTTP-ish status from the Contract client.
	 * @param string|null $reason Machine-readable reason, or null.
	 *
	 * @return string `self::RETRYABLE`, or one of `CutoverIncidentReason`'s codes.
	 */
	public static function classify( int $status, ?string $reason ): string {
		if ( 'handoff_provenance_conflict' === $reason ) {
			return CutoverIncidentReason::HANDOFF_PROVENANCE_CONFLICT;
		}

		if ( 404 === $status ) {
			return CutoverIncidentReason::UNRESOLVED_CASE_REFERENCE;
		}

		if ( null !== $reason && in_array( $reason, self::TRANSIENT_REASONS, true ) ) {
			return self::RETRYABLE;
		}

		// Every enumerated deterministic 400/409 refusal — and every
		// unrecognised ok:false reason — fails closed here (docs/adr/0043 §3).
		return CutoverIncidentReason::HANDOFF_REJECTED;
	}
}
