<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Telegram\Outbound;

use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;
use UniversalTelegram\Telegram\Configuration\DestinationKind;
use UniversalTelegram\Telegram\Configuration\DestinationRepository;
use UniversalTelegram\Telegram\Outbound\OutboundMessageRepository;
use UniversalTelegram\Telegram\Outbound\OutboundMessageStatus;
use UniversalTelegram\Telegram\Outbound\RetentionCleanupHandler;
use WP_UnitTestCase;

final class RetentionCleanupHandlerTest extends WP_UnitTestCase {

	private function age_message( int $id, int $days_old ): void {
		global $wpdb;

		$wpdb->update(
			$wpdb->prefix . 'universal_telegram_outbound_messages',
			array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - ( $days_old * DAY_IN_SECONDS ) ) ),
			array( 'id' => $id )
		);
	}

	public function test_a_sent_message_older_than_message_retention_has_its_body_nulled_but_metadata_kept(): void {
		$schema_health = new SchemaHealth();
		$vault         = new CredentialVault();

		$bots         = new BotProfileRepository( $schema_health, $vault );
		$destinations = new DestinationRepository( $schema_health );
		$messages     = new OutboundMessageRepository( $schema_health, $vault );

		$bot         = $bots->create( 'Bot', 'token' );
		$destination = $destinations->create( $bot->id(), DestinationKind::PRIVATE, '123', null, 'Chat' );
		$message     = $messages->create( $bot->id(), $destination->id(), 'old content', null );
		$messages->mark_sent( $message->id(), 111 );
		$this->age_message( $message->id(), 31 );

		$handler = new RetentionCleanupHandler( $messages, 30, 90 );
		$handler->run();

		$after = $messages->find( $message->id() );
		$this->assertSame( OutboundMessageStatus::PURGED, $after->status() );
		$this->assertNull( $after->body_ciphertext() );
	}

	public function test_a_recently_sent_message_is_left_untouched(): void {
		$schema_health = new SchemaHealth();
		$vault         = new CredentialVault();

		$bots         = new BotProfileRepository( $schema_health, $vault );
		$destinations = new DestinationRepository( $schema_health );
		$messages     = new OutboundMessageRepository( $schema_health, $vault );

		$bot         = $bots->create( 'Bot', 'token' );
		$destination = $destinations->create( $bot->id(), DestinationKind::PRIVATE, '123', null, 'Chat' );
		$message     = $messages->create( $bot->id(), $destination->id(), 'fresh content', null );
		$messages->mark_sent( $message->id(), 111 );

		$handler = new RetentionCleanupHandler( $messages, 30, 90 );
		$handler->run();

		$after = $messages->find( $message->id() );
		$this->assertSame( OutboundMessageStatus::SENT, $after->status() );
		$this->assertNotNull( $after->body_ciphertext() );
	}

	public function test_a_row_older_than_delivery_log_retention_is_deleted_entirely(): void {
		$schema_health = new SchemaHealth();
		$vault         = new CredentialVault();

		$bots         = new BotProfileRepository( $schema_health, $vault );
		$destinations = new DestinationRepository( $schema_health );
		$messages     = new OutboundMessageRepository( $schema_health, $vault );

		$bot         = $bots->create( 'Bot', 'token' );
		$destination = $destinations->create( $bot->id(), DestinationKind::PRIVATE, '123', null, 'Chat' );
		$message     = $messages->create( $bot->id(), $destination->id(), 'ancient content', null );
		$messages->mark_dead_letter( $message->id(), 'telegram_terminal_rejection' );
		$this->age_message( $message->id(), 91 );

		$handler = new RetentionCleanupHandler( $messages, 30, 90 );
		$handler->run();

		$this->assertNull( $messages->find( $message->id() ) );
	}
}
