<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Telegram\Outbound;

use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Queue\DispatchState;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;
use UniversalTelegram\Telegram\Configuration\DestinationKind;
use UniversalTelegram\Telegram\Configuration\DestinationRepository;
use UniversalTelegram\Telegram\Outbound\MessageDispatcher;
use UniversalTelegram\Telegram\Outbound\OutboundMessageRepository;
use UniversalTelegram\Telegram\Outbound\OutboundMessageStatus;
use UniversalTelegram\Queue\Dispatcher;
use WP_UnitTestCase;

final class MessageDispatcherTest extends WP_UnitTestCase {

	public function test_send_stores_content_before_enqueueing_an_opaque_reference(): void {
		$schema_health = new SchemaHealth();
		$vault         = new CredentialVault();

		$bots         = new BotProfileRepository( $schema_health, $vault );
		$destinations = new DestinationRepository( $schema_health );
		$messages     = new OutboundMessageRepository( $schema_health, $vault );

		$bot         = $bots->create( 'Bot', 'token' );
		$destination = $destinations->create( $bot->id(), DestinationKind::PRIVATE, '123', null, 'Chat' );

		$dispatcher = new MessageDispatcher( $messages, new Dispatcher( $schema_health ) );
		$result     = $dispatcher->send( $bot->id(), $destination->id(), 'Hello there, this is confidential.' );

		$this->assertNotNull( $result );
		$this->assertSame( DispatchState::SCHEDULED, $result->state() );

		// Exactly one message row was created, pending, with encrypted content.
		global $wpdb;
		$table = $wpdb->prefix . 'universal_telegram_outbound_messages';
		$row   = $wpdb->get_row( "SELECT * FROM {$table} WHERE bot_id = {$bot->id()}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$this->assertNotNull( $row );
		$this->assertSame( OutboundMessageStatus::PENDING->value, $row['status'] );
		$this->assertStringNotContainsString( 'Hello there, this is confidential.', (string) $row['body_ciphertext'] );
	}

	public function test_schema_unavailable_refuses_without_enqueueing(): void {
		$schema_health = new SchemaHealth();
		$vault         = new CredentialVault();

		$bots         = new BotProfileRepository( $schema_health, $vault );
		$destinations = new DestinationRepository( $schema_health );

		$bot         = $bots->create( 'Bot', 'token' );
		$destination = $destinations->create( $bot->id(), DestinationKind::PRIVATE, '123', null, 'Chat' );

		$degraded_schema = new SchemaHealth();
		$degraded_schema->mark_unavailable( \UniversalTelegram\Persistence\MigrationFailureCode::STEP_FAILED );

		$messages   = new OutboundMessageRepository( $degraded_schema, $vault );
		$dispatcher = new MessageDispatcher( $messages, new Dispatcher( $degraded_schema ) );

		$result = $dispatcher->send( $bot->id(), $destination->id(), 'hi' );

		$this->assertNull( $result );
	}
}
