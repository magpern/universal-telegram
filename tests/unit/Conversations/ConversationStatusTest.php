<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Unit\Conversations;

use PHPUnit\Framework\TestCase;
use UniversalTelegram\Conversations\ConversationStatus;

final class ConversationStatusTest extends TestCase {

	/**
	 * @dataProvider allowed_transitions_provider
	 */
	public function test_allowed_transitions_are_valid( string $from, string $to ): void {
		$this->assertTrue( ConversationStatus::is_valid_transition( $from, $to ) );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function allowed_transitions_provider(): array {
		return array(
			'new to open'                       => array( ConversationStatus::NEW, ConversationStatus::OPEN ),
			'open to waiting_for_visitor'       => array( ConversationStatus::OPEN, ConversationStatus::WAITING_FOR_VISITOR ),
			'open to waiting_for_operator'      => array( ConversationStatus::OPEN, ConversationStatus::WAITING_FOR_OPERATOR ),
			'waiting_for_visitor back to open'  => array( ConversationStatus::WAITING_FOR_VISITOR, ConversationStatus::OPEN ),
			'waiting_for_operator back to open' => array( ConversationStatus::WAITING_FOR_OPERATOR, ConversationStatus::OPEN ),
			'open to resolved'                  => array( ConversationStatus::OPEN, ConversationStatus::RESOLVED ),
			'waiting_for_visitor to resolved'   => array( ConversationStatus::WAITING_FOR_VISITOR, ConversationStatus::RESOLVED ),
			'waiting_for_operator to resolved'  => array( ConversationStatus::WAITING_FOR_OPERATOR, ConversationStatus::RESOLVED ),
			'resolved to archived'              => array( ConversationStatus::RESOLVED, ConversationStatus::ARCHIVED ),
		);
	}

	/**
	 * @dataProvider disallowed_transitions_provider
	 */
	public function test_disallowed_transitions_are_rejected( string $from, string $to ): void {
		$this->assertFalse( ConversationStatus::is_valid_transition( $from, $to ) );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function disallowed_transitions_provider(): array {
		return array(
			'new cannot go directly to resolved'     => array( ConversationStatus::NEW, ConversationStatus::RESOLVED ),
			'new cannot go directly to archived'     => array( ConversationStatus::NEW, ConversationStatus::ARCHIVED ),
			'open cannot go directly to archived'    => array( ConversationStatus::OPEN, ConversationStatus::ARCHIVED ),
			'archived cannot transition anywhere'    => array( ConversationStatus::ARCHIVED, ConversationStatus::OPEN ),
			'resolved cannot go back to open'        => array( ConversationStatus::RESOLVED, ConversationStatus::OPEN ),
			'unknown status has no valid transition' => array( 'nonexistent', ConversationStatus::OPEN ),
		);
	}
}
