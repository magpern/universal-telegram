<?php
/**
 * ADR-0044 interop: Support Chat's notify_operators and deliver_message
 * Contract calls flow through the REAL Universal Telegram transport — an
 * encrypted universal_telegram_outbound_messages row bound to the adapter's
 * destination — never a legacy conversation-message write.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Tests\Integration\Interop;

use UniversalSupportChat\ChannelContract\Outbound\IdempotencyKeys;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\SupportChatAdapter\DeliveryIdempotencyRepository;
use UniversalTelegram\Telegram\Outbound\OutboundMessageRepository;

/**
 * @coversNothing
 */
final class TransportPathInteropTest extends InteropTestCase {

	public function test_deliver_message_creates_a_real_encrypted_transport_message(): void {
		$conversation_uuid = wp_generate_uuid4();
		$ref               = $this->ensure_ut_channel_case( $conversation_uuid );
		$message_uuid      = wp_generate_uuid4();

		$before = $this->outbound_message_count();

		$result = $this->sc_outbound_client->deliver_message( 'universal-telegram', $ref, $message_uuid, 'Hello from Support Chat', 'Operator' );
		self::assertTrue( $result['ok'], (string) $result['reason'] );
		self::assertGreaterThan( $before, $this->outbound_message_count(), 'a transport outbound_messages row must have been created' );

		$keys    = new DeliveryIdempotencyRepository( new SchemaHealth() );
		$key_row = $keys->find( IdempotencyKeys::for_message_delivery( $message_uuid ) );
		self::assertIsArray( $key_row );
		self::assertNotNull( $key_row['outbound_message_uuid'] ?? null );

		$repo     = new OutboundMessageRepository( new SchemaHealth(), new CredentialVault() );
		$outbound = $repo->find_by_uuid( (string) $key_row['outbound_message_uuid'] );
		self::assertNotNull( $outbound );

		$decrypted = $repo->decrypt_body( $outbound );
		self::assertNotNull( $decrypted );
		self::assertStringContainsString( 'Hello from Support Chat', (string) $decrypted->plaintext() );
	}

	public function test_deliver_message_is_idempotent_on_the_message_uuid(): void {
		$conversation_uuid = wp_generate_uuid4();
		$ref               = $this->ensure_ut_channel_case( $conversation_uuid );
		$message_uuid      = wp_generate_uuid4();

		$first       = $this->sc_outbound_client->deliver_message( 'universal-telegram', $ref, $message_uuid, 'One', 'Operator' );
		$after_first = $this->outbound_message_count();
		$second      = $this->sc_outbound_client->deliver_message( 'universal-telegram', $ref, $message_uuid, 'One', 'Operator' );

		self::assertTrue( $first['ok'], (string) $first['reason'] );
		self::assertTrue( $second['ok'], (string) $second['reason'] );
		self::assertSame( $after_first, $this->outbound_message_count(), 'a repeated message_uuid must not create a second transport row' );
	}

	public function test_notify_operators_delivers_through_the_transport_for_an_active_binding(): void {
		$conversation_uuid = wp_generate_uuid4();
		$ref               = $this->ensure_ut_channel_case( $conversation_uuid );

		$before = $this->outbound_message_count();

		$result = $this->sc_outbound_client->notify_operators( 'universal-telegram', $ref, 'attention', 'A visitor needs help.' );
		self::assertTrue( $result['ok'], (string) $result['reason'] );
		self::assertGreaterThan( $before, $this->outbound_message_count(), 'notify_operators must create a transport outbound_messages row' );
	}

	private function outbound_message_count(): int {
		global $wpdb;

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}universal_telegram_outbound_messages" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- fixed test query.
	}
}
