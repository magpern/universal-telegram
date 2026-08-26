<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Unit\Migration;

use LogicException;
use PHPUnit\Framework\TestCase;
use UniversalTelegram\Migration\DeferredReplayContext;

/**
 * ADR-0040 §3/§7: DeferredReplayContext cannot be constructed outside
 * QuiescenceGate::issue_replay_context().
 */
final class DeferredReplayContextTest extends TestCase {

	public function test_issue_called_directly_from_outside_quiescence_gate_throws(): void {
		$this->expectException( LogicException::class );

		DeferredReplayContext::issue( 'forged-token' );
	}

	public function test_issue_called_from_within_a_helper_that_is_not_quiescence_gate_still_throws(): void {
		$this->expectException( LogicException::class );

		( function () {
			return DeferredReplayContext::issue( 'forged-token' );
		} )();
	}
}
