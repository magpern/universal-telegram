<?php
/**
 * Item 6: idempotent/safe retry on both directions.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Tests\Integration\Interop;

final class IdempotencyTest extends InteropTestCase {

	/** UT -> SC: ingest_operator_reply sent twice with the same idempotency key creates exactly one message. */
	public function test_ut_to_sc_ingest_operator_reply_is_idempotent(): void {
		$uuid = $this->create_sc_conversation();

		$first  = $this->ut_outbound_client->ingest_operator_reply( $uuid, 'idem-dup-1', 'Only once', 3 );
		$second = $this->ut_outbound_client->ingest_operator_reply( $uuid, 'idem-dup-1', 'Only once', 3 );

		self::assertTrue( $first['ok'] );
		self::assertTrue( $second['ok'] );

		$conversation = $this->sc_conversations->find_by_uuid( $uuid );
		self::assertNotNull( $conversation );
		$messages = $this->sc_messages->list_for_conversation( $conversation->id() );
		self::assertCount( 1, $messages, 'Duplicate delivery of the same idempotency key must not create a second message.' );
	}

	/** SC -> UT: ensure_channel_case is idempotent on conversation identity — repeated calls resolve to the same channel_case_ref. */
	public function test_sc_to_ut_ensure_channel_case_is_idempotent(): void {
		$conversation_uuid = wp_generate_uuid4();

		$first  = $this->sc_outbound_client->ensure_channel_case( 'universal-telegram', $conversation_uuid, 'escalated' );
		$second = $this->sc_outbound_client->ensure_channel_case( 'universal-telegram', $conversation_uuid, 'escalated' );

		self::assertTrue( $first['ok'] );
		self::assertTrue( $second['ok'] );
		self::assertSame( $first['channel_case_ref'], $second['channel_case_ref'] );
		self::assertSame( 'created', $first['case_status'] );
		self::assertSame( 'reused', $second['case_status'] );

		global $wpdb;
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'universal_telegram_support_chat_bindings WHERE support_conversation_uuid = %s', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$conversation_uuid
			)
		);
		self::assertSame( 1, $count, 'Duplicate ensure_channel_case must never create a second binding row.' );
	}

	/** SC -> UT: deliver_message is idempotent on message_uuid — a re-sent duplicate delivery reports reused, no second outbound row. */
	public function test_sc_to_ut_deliver_message_is_idempotent(): void {
		$conversation_uuid = wp_generate_uuid4();
		$ref               = $this->ensure_ut_channel_case( $conversation_uuid );
		$message_uuid      = wp_generate_uuid4();

		$first  = $this->sc_outbound_client->deliver_message( 'universal-telegram', $ref, $message_uuid, 'Retried body', 'Operator' );
		$second = $this->sc_outbound_client->deliver_message( 'universal-telegram', $ref, $message_uuid, 'Retried body', 'Operator' );

		self::assertTrue( $first['ok'] );
		self::assertFalse( $first['reused'] );
		self::assertTrue( $second['ok'] );
		self::assertTrue( $second['reused'], 'A retried delivery of the same message_uuid must be reported as reused.' );
	}
}
