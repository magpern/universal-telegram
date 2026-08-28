<?php
/**
 * ADR-0044 interop: a Telegram operator's reply in a bound forum topic is
 * attributed through the retained OperatorIdentityMap and reaches the REAL
 * Support Chat conversation as an operator message — with no legacy
 * Universal Telegram conversation/message table touched.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Tests\Integration\Interop;

use UniversalSupportChat\Conversations\ConversationMessage;
use UniversalTelegram\Audit\AuditLogger;
use UniversalTelegram\Privacy\Redactor;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\SupportChatAdapter\Identity\OperatorIdentityMapRepository;
use UniversalTelegram\SupportChatAdapter\Inbound\InboundAdapterBridge;

/**
 * @coversNothing
 */
final class OperatorReplyAttributionInteropTest extends InteropTestCase {

	private OperatorIdentityMapRepository $identities;

	protected function setUp(): void {
		parent::setUp();
		$this->identities = new OperatorIdentityMapRepository( new SchemaHealth() );
	}

	public function test_mapped_telegram_operator_reply_reaches_the_real_sc_conversation_attributed_to_the_wp_operator(): void {
		$conversation_uuid = $this->create_sc_conversation();
		$this->ensure_ut_channel_case( $conversation_uuid );

		$binding = $this->ut_bindings->find_by_conversation_uuid( $conversation_uuid );
		self::assertNotNull( $binding );

		$operator_id      = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$telegram_user_id = 785412;
		self::assertNotNull( $this->identities->create( $operator_id, $telegram_user_id, 'op_telegram', $operator_id ) );

		$this->bridge()->try_handle(
			$this->bot(),
			(string) $this->parent_destination->chat_id(),
			$binding->telegram_topic_id(),
			$this->telegram_message_update( $telegram_user_id, $binding->telegram_topic_id(), 'Answered from Telegram', 90001 ),
			90001
		);

		$conversation = $this->sc_conversations->find_by_uuid( $conversation_uuid );
		self::assertNotNull( $conversation );

		$messages = $this->sc_messages->list_for_conversation( $conversation->id() );
		self::assertNotEmpty( $messages );

		$operator_messages = array_filter(
			$messages,
			static fn ( ConversationMessage $m ) => ConversationMessage::DIRECTION_OPERATOR === $m->direction()
		);
		self::assertNotEmpty( $operator_messages );
		$last = array_pop( $operator_messages );
		self::assertSame( 'Answered from Telegram', $last->plaintext_body() );

		// Attribution: the SC-side audit row for the ingest carries the WP
		// user id the OperatorIdentityMap resolved from the Telegram sender.
		global $wpdb;
		$actor_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT actor_id FROM {$wpdb->prefix}universal_support_chat_audit_log WHERE action = %s ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name.
				'contract.ingest_operator_reply'
			)
		);
		self::assertSame( $operator_id, (int) $actor_id );
	}

	public function test_unmapped_telegram_sender_is_not_ingested_into_support_chat(): void {
		$conversation_uuid = $this->create_sc_conversation();
		$this->ensure_ut_channel_case( $conversation_uuid );

		$binding = $this->ut_bindings->find_by_conversation_uuid( $conversation_uuid );
		self::assertNotNull( $binding );

		$conversation = $this->sc_conversations->find_by_uuid( $conversation_uuid );
		self::assertNotNull( $conversation );
		$before = count( $this->sc_messages->list_for_conversation( $conversation->id() ) );

		// No OperatorIdentityMap row for this Telegram sender.
		$this->bridge()->try_handle(
			$this->bot(),
			(string) $this->parent_destination->chat_id(),
			$binding->telegram_topic_id(),
			$this->telegram_message_update( 999333, $binding->telegram_topic_id(), 'Reply from a stranger', 90002 ),
			90002
		);

		self::assertCount( $before, $this->sc_messages->list_for_conversation( $conversation->id() ) );
	}

	public function test_no_legacy_universal_telegram_conversation_table_exists(): void {
		global $wpdb;

		foreach ( array( 'conversations', 'conversation_messages', 'conversation_notes', 'operator_identities' ) as $legacy ) {
			$name  = $wpdb->prefix . 'universal_telegram_' . $legacy;
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) );
			self::assertNull( $found, "legacy table {$legacy} must not exist" );
		}
	}

	private function bridge(): InboundAdapterBridge {
		return new InboundAdapterBridge(
			$this->ut_bindings,
			$this->ut_discovery,
			$this->ut_outbound_client,
			$this->identities,
			new AuditLogger( new SchemaHealth(), new Redactor() ),
			true
		);
	}

	private function bot(): \UniversalTelegram\Telegram\Configuration\BotProfile {
		$bot = $this->ut_bots->find( $this->bot_id );
		self::assertNotNull( $bot );

		return $bot;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function telegram_message_update( int $from_id, int $thread_id, string $text, int $update_id ): array {
		return array(
			'update_id' => $update_id,
			'message'   => array(
				'message_id'        => $update_id,
				'message_thread_id' => $thread_id,
				'from'              => array(
					'id'         => $from_id,
					'is_bot'     => false,
					'first_name' => 'Op',
				),
				'chat'              => array(
					'id'   => (int) $this->parent_destination->chat_id(),
					'type' => 'supergroup',
				),
				'text'              => $text,
			),
		);
	}
}
