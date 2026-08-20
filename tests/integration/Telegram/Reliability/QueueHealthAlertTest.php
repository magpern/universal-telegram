<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Telegram\Reliability;

use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Queue\RetryPolicy;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;
use UniversalTelegram\Telegram\Configuration\DestinationKind;
use UniversalTelegram\Telegram\Configuration\DestinationRepository;
use UniversalTelegram\Telegram\Outbound\OutboundMessageRepository;
use UniversalTelegram\Telegram\Reliability\CircuitBreaker;
use UniversalTelegram\Telegram\Reliability\QueueHealthAlert;
use WP_UnitTestCase;

final class QueueHealthAlertTest extends WP_UnitTestCase {

	private function bots( SchemaHealth $schema_health, CredentialVault $vault ): BotProfileRepository {
		return new BotProfileRepository( $schema_health, $vault );
	}

	public function test_no_condition_seeded_is_inactive(): void {
		$schema_health = new SchemaHealth();
		$vault         = new CredentialVault();
		$messages      = new OutboundMessageRepository( $schema_health, $vault );
		$breaker       = new CircuitBreaker( $schema_health, new RetryPolicy() );

		$alert = new QueueHealthAlert( $messages, $breaker, $this->bots( $schema_health, $vault ) );

		$this->assertFalse( $alert->is_active( 1800 ) );
	}

	public function test_a_dead_lettered_message_activates_the_alert(): void {
		$schema_health = new SchemaHealth();
		$vault         = new CredentialVault();
		$messages      = new OutboundMessageRepository( $schema_health, $vault );
		$bots          = $this->bots( $schema_health, $vault );
		$destinations  = new DestinationRepository( $schema_health );
		$breaker       = new CircuitBreaker( $schema_health, new RetryPolicy() );

		$bot         = $bots->create( 'Bot', 'token' );
		$destination = $destinations->create( $bot->id(), DestinationKind::PRIVATE, '123', null, 'Chat' );
		$message     = $messages->create( $bot->id(), $destination->id(), 'hi', null );
		$messages->mark_dead_letter( $message->id(), 'telegram_terminal_rejection' );

		$alert = new QueueHealthAlert( $messages, $breaker, $bots );

		$this->assertTrue( $alert->is_active( 1800 ) );
		$this->assertSame( 1, $alert->details( 1800 )['dead_letter_count'] );
	}

	public function test_an_open_circuit_breaker_activates_the_alert(): void {
		$schema_health = new SchemaHealth();
		$vault         = new CredentialVault();
		$messages      = new OutboundMessageRepository( $schema_health, $vault );
		$breaker       = new CircuitBreaker( $schema_health, new RetryPolicy() );

		$breaker->open_indefinitely( 'bot', 1 );

		$alert = new QueueHealthAlert( $messages, $breaker, $this->bots( $schema_health, $vault ) );

		$this->assertTrue( $alert->is_active( 1800 ) );
		$this->assertTrue( $alert->details( 1800 )['any_circuit_breaker_open'] );
	}

	public function test_a_stale_pending_message_activates_the_alert(): void {
		$schema_health = new SchemaHealth();
		$vault         = new CredentialVault();
		$messages      = new OutboundMessageRepository( $schema_health, $vault );
		$bots          = $this->bots( $schema_health, $vault );
		$destinations  = new DestinationRepository( $schema_health );
		$breaker       = new CircuitBreaker( $schema_health, new RetryPolicy() );

		$bot         = $bots->create( 'Bot', 'token' );
		$destination = $destinations->create( $bot->id(), DestinationKind::PRIVATE, '123', null, 'Chat' );
		$message     = $messages->create( $bot->id(), $destination->id(), 'hi', null );

		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'universal_telegram_outbound_messages',
			array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 3600 ) ),
			array( 'id' => $message->id() )
		);

		$alert = new QueueHealthAlert( $messages, $breaker, $bots );

		$this->assertTrue( $alert->is_active( 1800 ) );
		$this->assertFalse( $alert->is_active( 7200 ) );
	}

	public function test_a_stale_unresolved_registration_activates_the_alert(): void {
		$schema_health = new SchemaHealth();
		$vault         = new CredentialVault();
		$messages      = new OutboundMessageRepository( $schema_health, $vault );
		$bots          = $this->bots( $schema_health, $vault );
		$breaker       = new CircuitBreaker( $schema_health, new RetryPolicy() );

		$bot = $bots->create( 'Bot', 'token' );
		$bots->mark_uncertain( $bot->id() );
		$bots->touch_last_attempt( $bot->id() );

		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'universal_telegram_bots',
			array( 'webhook_last_attempt_at' => gmdate( 'Y-m-d H:i:s', time() - ( 48 * HOUR_IN_SECONDS ) ) ),
			array( 'id' => $bot->id() )
		);

		$alert = new QueueHealthAlert( $messages, $breaker, $bots );

		$this->assertTrue( $alert->is_active( 1800, 24 ) );
		$this->assertSame( 1, $alert->details( 1800, 24 )['stale_unresolved_registrations_count'] );
	}
}
