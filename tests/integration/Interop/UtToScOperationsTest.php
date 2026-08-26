<?php
/**
 * Item 4: UT -> SC, all 8 operations via the real SupportChatContractClient
 * hitting SC's real authenticated Contract route, asserting real SC domain
 * mutation (not just HTTP 200).
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Tests\Integration\Interop;

use UniversalSupportChat\Conversations\ConversationStatus;

final class UtToScOperationsTest extends InteropTestCase {

	public function test_ingest_operator_reply_creates_real_sc_message(): void {
		$uuid = $this->create_sc_conversation();

		$result = $this->ut_outbound_client->ingest_operator_reply( $uuid, 'idem-ingest-1', 'Hello from Telegram', 7 );
		self::assertTrue( $result['ok'], (string) $result['reason'] );

		$conversation = $this->sc_conversations->find_by_uuid( $uuid );
		self::assertNotNull( $conversation );
		$messages = $this->sc_messages->list_for_conversation( $conversation->id() );
		self::assertNotEmpty( $messages );
		self::assertSame( 'Hello from Telegram', $messages[0]->plaintext_body() );
	}

	public function test_claim_transitions_real_sc_conversation(): void {
		$uuid        = $this->create_sc_conversation();
		$operator_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$result = $this->ut_outbound_client->claim( $uuid, $operator_id, 'idem-claim-1' );
		self::assertTrue( $result['ok'], (string) $result['reason'] );

		$conversation = $this->sc_conversations->find_by_uuid( $uuid );
		self::assertNotNull( $conversation );
		self::assertSame( $operator_id, $conversation->assigned_operator_id() );
	}

	public function test_release_unclaims_real_sc_conversation(): void {
		$uuid        = $this->create_sc_conversation();
		$operator_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		self::assertTrue( $this->ut_outbound_client->claim( $uuid, $operator_id, 'idem-claim-2' )['ok'] );

		$result = $this->ut_outbound_client->release( $uuid, $operator_id, 'idem-release-1' );
		self::assertTrue( $result['ok'], (string) $result['reason'] );

		$conversation = $this->sc_conversations->find_by_uuid( $uuid );
		self::assertNotNull( $conversation );
		self::assertNull( $conversation->assigned_operator_id() );
	}

	public function test_resolve_transitions_real_sc_conversation(): void {
		$uuid = $this->create_sc_conversation();

		$result = $this->ut_outbound_client->resolve( $uuid, 0, 'idem-resolve-1' );
		self::assertTrue( $result['ok'], (string) $result['reason'] );

		$conversation = $this->sc_conversations->find_by_uuid( $uuid );
		self::assertNotNull( $conversation );
		self::assertSame( ConversationStatus::RESOLVED, $conversation->status() );
	}

	public function test_reopen_transitions_real_sc_conversation(): void {
		$uuid = $this->create_sc_conversation();
		self::assertTrue( $this->ut_outbound_client->resolve( $uuid, 0, 'idem-resolve-2' )['ok'] );

		$result = $this->ut_outbound_client->reopen( $uuid, 0, 'idem-reopen-1' );
		self::assertTrue( $result['ok'], (string) $result['reason'] );

		$conversation = $this->sc_conversations->find_by_uuid( $uuid );
		self::assertNotNull( $conversation );
		self::assertNotSame( ConversationStatus::RESOLVED, $conversation->status() );
	}

	public function test_update_assignment_changes_real_sc_conversation(): void {
		$uuid = $this->create_sc_conversation();
		$op_a = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$op_b = self::factory()->user->create( array( 'role' => 'administrator' ) );
		self::assertTrue( $this->ut_outbound_client->claim( $uuid, $op_a, 'idem-claim-3' )['ok'] );

		$result = $this->ut_outbound_client->update_assignment( $uuid, $op_b, 'idem-assign-1' );
		self::assertTrue( $result['ok'], (string) $result['reason'] );

		$conversation = $this->sc_conversations->find_by_uuid( $uuid );
		self::assertNotNull( $conversation );
		self::assertSame( $op_b, $conversation->assigned_operator_id() );
	}

	public function test_report_channel_unavailable_marks_real_sc_channel_status(): void {
		$uuid         = $this->create_sc_conversation();
		$conversation = $this->sc_conversations->find_by_uuid( $uuid );
		self::assertNotNull( $conversation );

		$result = $this->ut_outbound_client->report_channel_unavailable( $uuid, 'peer_unreachable' );
		self::assertTrue( $result['ok'], (string) $result['reason'] );

		$status = $this->sc_channel_status->status_for( $conversation->id() );
		self::assertSame( \UniversalSupportChat\ChannelContract\ChannelStatusRepository::STATUS_DEGRADED, $status['status'] );
	}

	public function test_report_delivery_failure_marks_real_sc_channel_status(): void {
		$uuid         = $this->create_sc_conversation();
		$conversation = $this->sc_conversations->find_by_uuid( $uuid );
		self::assertNotNull( $conversation );

		$result = $this->ut_outbound_client->report_delivery_failure( $uuid, 'idem-deliver-1', 'send_failed' );
		self::assertTrue( $result['ok'], (string) $result['reason'] );

		$status = $this->sc_channel_status->status_for( $conversation->id() );
		self::assertSame( \UniversalSupportChat\ChannelContract\ChannelStatusRepository::STATUS_DEGRADED, $status['status'] );
	}
}
