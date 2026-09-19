<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Automations;

use UniversalTelegram\Automations\DispatchLogRepository;
use UniversalTelegram\Automations\NotificationDispatcher;
use UniversalTelegram\Automations\NotificationRule;
use UniversalTelegram\Automations\TemplateRenderer;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Events\EventEnvelope;
use UniversalTelegram\Events\EventSource;
use UniversalTelegram\Events\Registry;
use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Privacy\Classification;
use UniversalTelegram\Queue\Dispatcher;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;
use UniversalTelegram\Telegram\Configuration\BotStatus;
use UniversalTelegram\Telegram\Configuration\DestinationKind;
use UniversalTelegram\Telegram\Configuration\DestinationRepository;
use UniversalTelegram\Telegram\Outbound\MessageDispatcher;
use UniversalTelegram\Telegram\Outbound\OutboundMessageRepository;
use WP_UnitTestCase;

/**
 * ADR-0046: an event type's registered `reply_correlation_field` becomes the outbound
 * message's `ticket:{id}` token; event types without one send exactly as before.
 */
final class NotificationDispatcherCorrelationTest extends WP_UnitTestCase {

	private function registry(): Registry {
		$registry = new Registry();
		$map      = array( 'subject.ticket_id' => Classification::INTERNAL );
		$registry->register( 'support.correlated', 1, $map, array( 'subject.ticket_id' ), array(), 'subject.ticket_id' );
		$registry->register( 'support.plain', 1, $map, array( 'subject.ticket_id' ), array() );

		return $registry;
	}

	private function tokens(): array {
		global $wpdb;

		$table = $wpdb->prefix . Migrator::OUTBOUND_MESSAGES_TABLE;

		return $wpdb->get_col( "SELECT correlation_token FROM {$table} ORDER BY id ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	private function dispatch( string $event_type, string $key, array $subject ): void {
		$schema_health = new SchemaHealth();
		$vault         = new CredentialVault();
		$bots          = new BotProfileRepository( $schema_health, $vault );
		$destinations  = new DestinationRepository( $schema_health );
		$registry      = $this->registry();

		$bot = $bots->create( 'Bot', 'fake-token' );
		$bots->set_status( $bot->id(), BotStatus::ACTIVE );
		$destination = $destinations->create( $bot->id(), DestinationKind::PRIVATE, '123', null, 'Label' );

		$dispatcher = new NotificationDispatcher(
			new DispatchLogRepository( $schema_health ),
			$bots,
			$destinations,
			$registry,
			new TemplateRenderer(),
			new MessageDispatcher( new OutboundMessageRepository( $schema_health, $vault ), new Dispatcher( $schema_health ) )
		);

		$rule  = new NotificationRule( 1, 'Rule', $event_type, 1, array(), 'all', $bot->id(), $destination->id(), 'Ticket {{ subject.ticket_id }}', true, 100, 0, 'now', 'now' );
		$event = new EventEnvelope( $registry, $event_type, $key, EventSource::CUSTOM, array(), $subject, array(), array() );

		$dispatcher->dispatch( $rule, $event );
	}

	public function test_a_correlated_event_type_produces_a_ticket_token(): void {
		$this->dispatch( 'support.correlated', 'k1', array( 'ticket_id' => 42 ) );

		$this->assertSame( array( 'ticket:42' ), $this->tokens() );
	}

	public function test_an_event_type_without_a_correlation_field_sends_without_a_token(): void {
		$this->dispatch( 'support.plain', 'k2', array( 'ticket_id' => 42 ) );

		$this->assertSame( array( null ), $this->tokens() );
	}

	public function test_a_correlated_event_missing_the_field_value_sends_without_a_token(): void {
		$this->dispatch( 'support.correlated', 'k3', array() );

		$this->assertSame( array( null ), $this->tokens() );
	}
}
