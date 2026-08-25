<?php
/**
 * Unit tests for pinned Contract v1 operation lists.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Tests\SupportChatAdapter;

use PHPUnit\Framework\TestCase;
use UniversalTelegram\SupportChatAdapter\ContractConstants;

/**
 * @covers \UniversalTelegram\SupportChatAdapter\ContractConstants
 */
final class ContractConstantsTest extends TestCase {

	public function test_adapter_to_support_chat_operations_excludes_update_operator_presence(): void {
		$operations = ContractConstants::adapter_to_support_chat_operations();

		$this->assertNotContains( 'update_operator_presence', $operations );
		$this->assertContains( 'update_assignment', $operations );
		$this->assertCount( 8, $operations );
	}

	public function test_support_chat_to_adapter_operations_is_the_four_inbound_ops(): void {
		$this->assertSame(
			array( 'ensure_channel_case', 'notify_operators', 'deliver_transcript_backfill', 'deliver_message' ),
			ContractConstants::support_chat_to_adapter_operations()
		);
	}

	public function test_required_operations_matches_adapter_to_support_chat_operations(): void {
		$this->assertSame(
			ContractConstants::adapter_to_support_chat_operations(),
			ContractConstants::required_operations()
		);
	}

	public function test_is_valid_peer_allow_list_accepts_subset_of_inbound_operations(): void {
		$this->assertTrue( ContractConstants::is_valid_peer_allow_list( array( 'ensure_channel_case' ) ) );
	}

	public function test_is_valid_peer_allow_list_rejects_empty_list(): void {
		$this->assertFalse( ContractConstants::is_valid_peer_allow_list( array() ) );
	}

	public function test_is_valid_peer_allow_list_rejects_outbound_only_operation(): void {
		// 'claim' is an adapter->SC operation, never something SC calls on UT.
		$this->assertFalse( ContractConstants::is_valid_peer_allow_list( array( 'claim' ) ) );
	}

	public function test_is_valid_peer_allow_list_rejects_unknown_operation(): void {
		$this->assertFalse( ContractConstants::is_valid_peer_allow_list( array( 'delete_everything' ) ) );
	}

	public function test_is_valid_peer_allow_list_rejects_non_string_entries(): void {
		$this->assertFalse( ContractConstants::is_valid_peer_allow_list( array( 42 ) ) );
	}
}
