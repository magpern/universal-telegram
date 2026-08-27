<?php
/**
 * Unit tests for the exhaustive replay-failure classification (docs/adr/0043 §3).
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Tests\Unit\Migration;

use PHPUnit\Framework\TestCase;
use UniversalTelegram\Migration\CutoverIncidentReason;
use UniversalTelegram\Migration\CutoverReplayFailureClassifier;
use UniversalTelegram\SupportChatAdapter\Inbound\SupportChatContractClient;

/**
 * Every possible `ok:false` Contract outcome after active-binding selection
 * maps to exactly one named retryable outcome or one named incident — no
 * generic fallback that could produce an unbounded silent retry
 * (docs/adr/0043 §3).
 *
 * @covers \UniversalTelegram\Migration\CutoverReplayFailureClassifier
 */
final class CutoverReplayFailureClassifierTest extends TestCase {

	/**
	 * The frozen exhaustive table (docs/adr/0043 §3), one row per case.
	 *
	 * @return array<string, array{0: int, 1: ?string, 2: string}>
	 */
	public static function classification_table(): array {
		return array(
			'404 not_found → unresolved_case_reference'    => array( 404, 'not_found', CutoverIncidentReason::UNRESOLVED_CASE_REFERENCE ),
			'404 with any other reason → unresolved_case_reference' => array( 404, 'anything', CutoverIncidentReason::UNRESOLVED_CASE_REFERENCE ),
			'409 handoff_provenance_conflict → its own code' => array( 409, 'handoff_provenance_conflict', CutoverIncidentReason::HANDOFF_PROVENANCE_CONFLICT ),
			'400 invalid_body → handoff_rejected'          => array( 400, 'invalid_body', CutoverIncidentReason::HANDOFF_REJECTED ),
			'400 invalid_operator → handoff_rejected'      => array( 400, 'invalid_operator', CutoverIncidentReason::HANDOFF_REJECTED ),
			'400 unsupported_operation → handoff_rejected' => array( 400, 'unsupported_operation', CutoverIncidentReason::HANDOFF_REJECTED ),
			'409 already_claimed → handoff_rejected'       => array( 409, 'already_claimed', CutoverIncidentReason::HANDOFF_REJECTED ),
			'409 claimed_by_other → handoff_rejected'      => array( 409, 'claimed_by_other', CutoverIncidentReason::HANDOFF_REJECTED ),
			'409 invalid_transition → handoff_rejected'    => array( 409, 'invalid_transition', CutoverIncidentReason::HANDOFF_REJECTED ),
			'sc_contract_unsupported_operation → handoff_rejected' => array( 503, SupportChatContractClient::REASON_UNSUPPORTED_OPERATION, CutoverIncidentReason::HANDOFF_REJECTED ),
			'unrecognised ok:false reason → handoff_rejected' => array( 418, 'teapot_unknown', CutoverIncidentReason::HANDOFF_REJECTED ),
			'null reason → handoff_rejected (fail closed)' => array( 500, null, CutoverIncidentReason::HANDOFF_REJECTED ),
			'503 request_failed → retryable'               => array( 503, 'request_failed', CutoverReplayFailureClassifier::RETRYABLE ),
			'401 contract_auth_failed → retryable'         => array( 401, 'contract_auth_failed', CutoverReplayFailureClassifier::RETRYABLE ),
			'client not-paired → retryable'                => array( 503, SupportChatContractClient::REASON_NOT_PAIRED, CutoverReplayFailureClassifier::RETRYABLE ),
			'client unavailable → retryable'               => array( 503, SupportChatContractClient::UNAVAILABLE_REASON, CutoverReplayFailureClassifier::RETRYABLE ),
			'client discovery-incompatible → retryable'    => array( 503, SupportChatContractClient::REASON_DISCOVERY_INCOMPATIBLE, CutoverReplayFailureClassifier::RETRYABLE ),
			'client signing-unavailable → retryable'       => array( 503, SupportChatContractClient::REASON_SIGNING_UNAVAILABLE, CutoverReplayFailureClassifier::RETRYABLE ),
			'client transport-failed → retryable'          => array( 503, SupportChatContractClient::REASON_TRANSPORT_FAILED, CutoverReplayFailureClassifier::RETRYABLE ),
		);
	}

	/**
	 * @dataProvider classification_table
	 *
	 * @param int         $status   Contract status.
	 * @param string|null $reason Contract reason.
	 * @param string      $expected Expected classification.
	 */
	public function test_every_failure_maps_to_a_named_outcome( int $status, ?string $reason, string $expected ): void {
		$this->assertSame( $expected, CutoverReplayFailureClassifier::classify( $status, $reason ) );
	}

	public function test_result_is_always_retryable_or_a_valid_closed_incident_code(): void {
		foreach ( self::classification_table() as $row ) {
			list( $status, $reason ) = $row;
			$out                     = CutoverReplayFailureClassifier::classify( $status, $reason );

			$this->assertTrue(
				CutoverReplayFailureClassifier::RETRYABLE === $out
					|| in_array( $out, CutoverIncidentReason::all(), true ),
				sprintf( 'classify(%d, %s) returned an unclassified value: %s', $status, null === $reason ? 'null' : $reason, $out )
			);
		}
	}

	public function test_a_novel_reason_never_becomes_retryable(): void {
		// The defect F1 addressed: a genuinely unresolvable/refused handoff
		// must never fall into the retryable bucket where it would loop
		// forever without a classified outcome.
		$this->assertNotSame(
			CutoverReplayFailureClassifier::RETRYABLE,
			CutoverReplayFailureClassifier::classify( 404, 'not_found' )
		);
		$this->assertNotSame(
			CutoverReplayFailureClassifier::RETRYABLE,
			CutoverReplayFailureClassifier::classify( 409, 'invalid_transition' )
		);
		$this->assertNotSame(
			CutoverReplayFailureClassifier::RETRYABLE,
			CutoverReplayFailureClassifier::classify( 599, 'something_new_from_support_chat' )
		);
	}
}
