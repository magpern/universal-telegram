<?php
/**
 * ADR-0044 interop: Support Chat's real ensure_channel_case Contract call
 * reaches the transport-only adapter and creates an ACTIVE binding plus a
 * real Telegram forum topic (via the neutral ForumTopicService), never a
 * legacy conversation row.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Tests\Integration\Interop;

use UniversalTelegram\SupportChatAdapter\ChannelBinding;

/**
 * @coversNothing
 */
final class EnsureChannelCaseInteropTest extends InteropTestCase {

	public function test_ensure_channel_case_creates_an_active_binding_and_a_forum_topic(): void {
		$conversation_uuid = wp_generate_uuid4();

		$result = $this->sc_outbound_client->ensure_channel_case( 'universal-telegram', $conversation_uuid, 'escalated' );
		self::assertTrue( $result['ok'], (string) $result['reason'] );
		self::assertNotSame( '', (string) $result['channel_case_ref'] );

		$binding = $this->ut_bindings->find_by_conversation_uuid( $conversation_uuid );
		self::assertNotNull( $binding );

		// Active binding, created directly (ADR-0044 §4a) — never prepared.
		self::assertSame( ChannelBinding::STATUS_ACTIVE, $binding->status() );
		self::assertTrue( $binding->is_active() );

		// A real forum topic thread id was allocated through the faked
		// Telegram createForumTopic boundary.
		self::assertGreaterThan( 0, $binding->telegram_topic_id() );

		// The Contract channel_case_ref is the SC conversation UUID, never
		// the UT-local binding UUID (docs/adr/0043, retained by ADR-0044).
		self::assertSame( $conversation_uuid, $result['channel_case_ref'] );
		self::assertSame( $conversation_uuid, $binding->support_conversation_uuid() );
		self::assertNotSame( $binding->binding_uuid(), $result['channel_case_ref'] );
	}

	public function test_ensure_channel_case_is_idempotent_for_the_same_conversation(): void {
		$conversation_uuid = wp_generate_uuid4();

		$first  = $this->sc_outbound_client->ensure_channel_case( 'universal-telegram', $conversation_uuid, 'escalated' );
		$second = $this->sc_outbound_client->ensure_channel_case( 'universal-telegram', $conversation_uuid, 'escalated' );

		self::assertTrue( $first['ok'], (string) $first['reason'] );
		self::assertTrue( $second['ok'], (string) $second['reason'] );
		self::assertSame( $first['channel_case_ref'], $second['channel_case_ref'] );

		global $wpdb;
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}universal_telegram_support_chat_bindings WHERE support_conversation_uuid = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name.
				$conversation_uuid
			)
		);
		self::assertSame( 1, $count );
	}
}
