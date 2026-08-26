<?php
/**
 * Item 9: Telegram optionality — SC's normal Hub/widget conversation
 * workflow works with UT absent/deactivated/disabled/unavailable, and no
 * ordinary chat activity is mirrored to UT just because it is installed.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Tests\Integration\Interop;

final class OptionalityTest extends InteropTestCase {

	/** SC's own conversation lifecycle works fully with UT's peer disabled (simulated "UT unavailable"). */
	public function test_sc_conversation_lifecycle_works_with_ut_peer_disabled(): void {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'universal_telegram_support_chat_peers',
			array( 'status' => 'disabled' ),
			array( 'peer_id' => 'universal-support-chat' )
		);

		$owner_id     = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$conversation = $this->sc_conversations->create( $owner_id );
		self::assertNotNull( $conversation );

		$message = $this->sc_messages->create( $conversation->id(), \UniversalSupportChat\Conversations\ConversationMessage::DIRECTION_VISITOR, 'Hi, I need help', 'stored' );
		self::assertNotNull( $message );

		$opened = $this->sc_conversations->transition( $conversation, \UniversalSupportChat\Conversations\ConversationStatus::OPEN );
		self::assertNotNull( $opened );

		$transitioned = $this->sc_conversations->transition( $opened, \UniversalSupportChat\Conversations\ConversationStatus::RESOLVED );
		self::assertNotNull( $transitioned );
		self::assertSame( \UniversalSupportChat\Conversations\ConversationStatus::RESOLVED, $transitioned->status() );
	}

	/** SC's own conversation lifecycle works with UT's own adapter setting disabled. */
	public function test_sc_conversation_lifecycle_works_with_ut_adapter_setting_disabled(): void {
		update_option(
			\UniversalTelegram\Core\Configuration\Settings::OPTION_NAME,
			array_merge( $this->ut_settings->get(), array( 'support_chat_adapter_enabled' => false ) )
		);

		$owner_id     = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$conversation = $this->sc_conversations->create( $owner_id );
		self::assertNotNull( $conversation );
		self::assertNotNull( $this->sc_messages->create( $conversation->id(), \UniversalSupportChat\Conversations\ConversationMessage::DIRECTION_VISITOR, 'Still works', 'stored' ) );
	}

	/**
	 * An ordinary SC conversation event that is NOT one of the four explicit
	 * SC->UT contract ops (e.g. a plain visitor message being stored) must
	 * never trigger any UT-side call/binding just because UT is installed
	 * and paired.
	 */
	public function test_ordinary_sc_chat_activity_is_never_mirrored_to_ut(): void {
		$owner_id     = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$conversation = $this->sc_conversations->create( $owner_id );
		self::assertNotNull( $conversation );

		for ( $i = 0; $i < 5; $i++ ) {
			self::assertNotNull(
				$this->sc_messages->create( $conversation->id(), \UniversalSupportChat\Conversations\ConversationMessage::DIRECTION_VISITOR, 'Ordinary message ' . $i, 'stored' )
			);
		}

		self::assertNull( $this->ut_bindings->find_by_conversation_uuid( $conversation->uuid() ), 'Plain SC conversation activity must never auto-create a UT binding — only an explicit AdapterContractClient call does.' );

		global $wpdb;
		$outbound_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}universal_telegram_outbound_messages" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed test table name.
		self::assertSame( 0, $outbound_count, 'No UT outbound message may exist from ordinary SC chat activity alone.' );
	}
}
