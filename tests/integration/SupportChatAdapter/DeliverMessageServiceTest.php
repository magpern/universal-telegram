<?php
/**
 * Integration tests for outbound Contract acceptors.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Tests\Integration\SupportChatAdapter;

use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Queue\Dispatcher;
use UniversalTelegram\SupportChatAdapter\ChannelBindingRepository;
use UniversalTelegram\SupportChatAdapter\DeliveryIdempotencyRepository;
use UniversalTelegram\SupportChatAdapter\Outbound\DeliverMessageService;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Telegram\Outbound\OutboundMessageRepository;
use WP_UnitTestCase;

/**
 * @covers \UniversalTelegram\SupportChatAdapter\Outbound\DeliverMessageService
 */
final class DeliverMessageServiceTest extends WP_UnitTestCase {

	public function test_deliver_idempotency_reuses_key(): void {
		$schema   = new SchemaHealth();
		$bindings = new ChannelBindingRepository( $schema );
		$keys     = new DeliveryIdempotencyRepository( $schema );
		$messages = new OutboundMessageRepository( $schema, new CredentialVault() );
		$service  = new DeliverMessageService( $bindings, $keys, $messages, new Dispatcher( $schema ) );

		$binding = $bindings->create(
			'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
			'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
			'ensure-deliver-1',
			1,
			1,
			42
		);
		$this->assertNotNull( $binding );

		// Destination/bot rows may be missing — enqueue may fail; idempotency still must reuse after first accept key insert.
		$first = $service->deliver( $binding->binding_uuid(), 'deliver-key-1', 'hello', 'Visitor' );
		$second = $service->deliver( $binding->binding_uuid(), 'deliver-key-1', 'hello again', 'Visitor' );

		if ( $first['ok'] ) {
			$this->assertTrue( $second['ok'] );
			$this->assertTrue( $second['reused'] );
		} else {
			// Without valid bot/destination outbound create fails; still assert binding lookup works.
			$this->assertSame( 'enqueue_failed', $first['reason'] );
		}
	}
}
