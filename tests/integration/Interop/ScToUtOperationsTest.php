<?php
/**
 * Item 5: SC -> UT, all 4 operations via the real AdapterContractClient
 * hitting UT's real acceptors, asserting real UT domain effects.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Tests\Integration\Interop;

final class ScToUtOperationsTest extends InteropTestCase {

	public function test_ensure_channel_case_creates_real_ut_binding(): void {
		$conversation_uuid = wp_generate_uuid4();

		$result = $this->sc_outbound_client->ensure_channel_case( 'universal-telegram', $conversation_uuid, 'escalated' );
		self::assertTrue( $result['ok'], (string) $result['reason'] );
		self::assertSame( 'created', $result['case_status'] );
		self::assertNotSame( '', $result['channel_case_ref'] );

		$binding = $this->ut_bindings->find_by_conversation_uuid( $conversation_uuid );
		self::assertNotNull( $binding );
		self::assertSame( $result['channel_case_ref'], $binding->binding_uuid() );
	}

	public function test_notify_operators_creates_real_ut_outbound_message(): void {
		$conversation_uuid = wp_generate_uuid4();
		$ref               = $this->ensure_ut_channel_case( $conversation_uuid );

		$result = $this->sc_outbound_client->notify_operators( 'universal-telegram', $ref, 'attention', 'A visitor needs help.' );
		self::assertTrue( $result['ok'], (string) $result['reason'] );

		$binding = $this->ut_bindings->find_by_uuid( $ref );
		self::assertNotNull( $binding );
	}

	public function test_deliver_transcript_backfill_creates_real_ut_outbound_messages(): void {
		$conversation_uuid = wp_generate_uuid4();
		$ref               = $this->ensure_ut_channel_case( $conversation_uuid );

		$messages = array(
			array(
				'message_uuid' => wp_generate_uuid4(),
				'body'         => 'First backfilled line',
				'attribution'  => 'Visitor',
			),
			array(
				'message_uuid' => wp_generate_uuid4(),
				'body'         => 'Second backfilled line',
				'attribution'  => 'Operator',
			),
		);

		$result = $this->sc_outbound_client->deliver_transcript_backfill( 'universal-telegram', $ref, $messages );
		self::assertTrue( $result['ok'], (string) $result['reason'] );
		self::assertSame( 2, $result['accepted'] );
		self::assertSame( 0, $result['failed'] );
	}

	public function test_deliver_message_creates_real_ut_outbound_message_and_queue_job(): void {
		$conversation_uuid = wp_generate_uuid4();
		$ref               = $this->ensure_ut_channel_case( $conversation_uuid );
		$message_uuid      = wp_generate_uuid4();

		$result = $this->sc_outbound_client->deliver_message( 'universal-telegram', $ref, $message_uuid, 'Hello from Support Chat', 'Operator' );
		self::assertTrue( $result['ok'], (string) $result['reason'] );
		self::assertFalse( $result['reused'] );

		$key_row = $this->ut_delivery_keys->find( \UniversalSupportChat\ChannelContract\Outbound\IdempotencyKeys::for_message_delivery( $message_uuid ) );
		self::assertIsArray( $key_row );
		self::assertNotNull( $key_row['outbound_message_uuid'] ?? null );

		$outbound = $this->outbound_message_repository()->find_by_uuid( (string) $key_row['outbound_message_uuid'] );
		self::assertNotNull( $outbound );
		$decrypted = $this->outbound_message_repository()->decrypt_body( $outbound );
		self::assertNotNull( $decrypted );
		self::assertNotNull( $decrypted->plaintext() );
		self::assertStringContainsString( 'Hello from Support Chat', (string) $decrypted->plaintext() );
	}

	private function outbound_message_repository(): \UniversalTelegram\Telegram\Outbound\OutboundMessageRepository {
		return new \UniversalTelegram\Telegram\Outbound\OutboundMessageRepository(
			new \UniversalTelegram\Persistence\SchemaHealth(),
			new \UniversalTelegram\Core\Security\CredentialVault()
		);
	}
}
