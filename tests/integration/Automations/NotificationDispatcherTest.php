<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Automations;

use UniversalTelegram\Automations\DispatchLogRepository;
use UniversalTelegram\Automations\DispatchLogResult;
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

final class NotificationDispatcherTest extends WP_UnitTestCase {

	private function registry(): Registry {
		$registry = new Registry();
		$registry->register(
			'wordpress.user_registered',
			1,
			array( 'subject.user_id' => Classification::PUBLIC ),
			array( 'subject.user_id' ),
			array( 'subject.user_id' )
		);

		return $registry;
	}

	private function envelope( Registry $registry, string $idempotency_key = 'key-1' ): EventEnvelope {
		return new EventEnvelope( $registry, 'wordpress.user_registered', $idempotency_key, EventSource::WORDPRESS_CORE, array(), array( 'user_id' => 1 ), array(), array() );
	}

	private function build(): array {
		$schema_health      = new SchemaHealth();
		$vault              = new CredentialVault();
		$bots               = new BotProfileRepository( $schema_health, $vault );
		$destinations       = new DestinationRepository( $schema_health );
		$messages           = new OutboundMessageRepository( $schema_health, $vault );
		$queue_dispatcher   = new Dispatcher( $schema_health );
		$message_dispatcher = new MessageDispatcher( $messages, $queue_dispatcher );
		$dispatch_log       = new DispatchLogRepository( $schema_health );
		$registry           = $this->registry();

		$dispatcher = new NotificationDispatcher(
			$dispatch_log,
			$bots,
			$destinations,
			$registry,
			new TemplateRenderer(),
			$message_dispatcher
		);

		return compact( 'bots', 'destinations', 'dispatch_log', 'dispatcher', 'registry' );
	}

	private function active_bot_and_destination( BotProfileRepository $bots, DestinationRepository $destinations ): array {
		$bot = $bots->create( 'Bot', 'fake-token' );
		$bots->set_status( $bot->id(), BotStatus::ACTIVE );
		$destination = $destinations->create( $bot->id(), DestinationKind::PRIVATE, '123', null, 'Label' );

		return array( $bots->find( $bot->id() ), $destination );
	}

	private function outbound_message_count(): int {
		global $wpdb;

		$table = $wpdb->prefix . Migrator::OUTBOUND_MESSAGES_TABLE;

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
	}

	public function test_a_successful_dispatch_transitions_claimed_to_handed_off_to_m01(): void {
		global $wpdb;

		[ 'bots' => $bots, 'destinations' => $destinations, 'dispatch_log' => $dispatch_log, 'dispatcher' => $dispatcher, 'registry' => $registry ] = $this->build();
		[ $bot, $destination ] = $this->active_bot_and_destination( $bots, $destinations );

		$rule  = new NotificationRule( 1, 'Rule', 'wordpress.user_registered', 1, array(), 'all', $bot->id(), $destination->id(), 'Hello {{ subject.user_id }}', true, 100, 0, 'now', 'now' );
		$event = $this->envelope( $registry );

		$dispatcher->dispatch( $rule, $event );

		$table = $wpdb->prefix . Migrator::DISPATCH_LOG_TABLE;
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE rule_id = %d AND event_id = %s", $rule->id(), $event->event_id() ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		$this->assertSame( DispatchLogResult::HANDED_OFF_TO_M01->value, $row['result'] );
		$this->assertSame( 1, $this->outbound_message_count() );
	}

	public function test_replaying_the_same_event_id_never_calls_send_a_second_time(): void {
		[ 'bots' => $bots, 'destinations' => $destinations, 'dispatcher' => $dispatcher, 'registry' => $registry ] = $this->build();
		[ $bot, $destination ] = $this->active_bot_and_destination( $bots, $destinations );

		$rule  = new NotificationRule( 1, 'Rule', 'wordpress.user_registered', 1, array(), 'all', $bot->id(), $destination->id(), 'Hello', true, 100, 0, 'now', 'now' );
		$event = $this->envelope( $registry, 'same-key' );

		$dispatcher->dispatch( $rule, $event );
		$this->assertSame( 1, $this->outbound_message_count() );

		// A second occurrence of the exact same (rule_id, event_id) pair —
		// e.g. a genuine replay of the originating WordPress event.
		$dispatcher->dispatch( $rule, $event );

		$this->assertSame( 1, $this->outbound_message_count() );
	}

	public function test_cooldown_is_checked_only_against_handed_off_rows(): void {
		[ 'bots' => $bots, 'destinations' => $destinations, 'dispatch_log' => $dispatch_log, 'dispatcher' => $dispatcher, 'registry' => $registry ] = $this->build();
		[ $bot, $destination ] = $this->active_bot_and_destination( $bots, $destinations );

		$rule = new NotificationRule( 5, 'Rule', 'wordpress.user_registered', 1, array(), 'all', $bot->id(), $destination->id(), 'Hello', true, 100, 3600, 'now', 'now' );

		$dispatcher->dispatch( $rule, $this->envelope( $registry, 'cooldown-1' ) );
		$this->assertSame( 1, $this->outbound_message_count() );

		// A second, genuinely distinct event occurrence for the same rule,
		// within the cooldown window.
		$dispatcher->dispatch( $rule, $this->envelope( $registry, 'cooldown-2' ) );

		$this->assertSame( 1, $this->outbound_message_count() );

		global $wpdb;
		$table = $wpdb->prefix . Migrator::DISPATCH_LOG_TABLE;
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE rule_id = %d AND event_id = %s", $rule->id(), hash( 'sha256', 'wordpress.user_registered' . "\x1f" . '1' . "\x1f" . 'cooldown-2' ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		$this->assertSame( DispatchLogResult::SKIPPED_COOLDOWN->value, $row['result'] );
	}

	public function test_a_disabled_destination_skips_without_sending(): void {
		[ 'bots' => $bots, 'destinations' => $destinations, 'dispatcher' => $dispatcher, 'registry' => $registry ] = $this->build();
		[ $bot, $destination ] = $this->active_bot_and_destination( $bots, $destinations );
		$destinations->set_enabled( $destination->id(), false );

		$rule = new NotificationRule( 1, 'Rule', 'wordpress.user_registered', 1, array(), 'all', $bot->id(), $destination->id(), 'Hello', true, 100, 0, 'now', 'now' );

		$dispatcher->dispatch( $rule, $this->envelope( $registry ) );

		$this->assertSame( 0, $this->outbound_message_count() );

		global $wpdb;
		$table = $wpdb->prefix . Migrator::DISPATCH_LOG_TABLE;
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE rule_id = %d", $rule->id() ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		$this->assertSame( DispatchLogResult::SKIPPED_DISABLED_REFERENCE->value, $row['result'] );
	}

	public function test_notification_dispatcher_has_no_dependency_on_job_envelope_or_queue_dispatcher(): void {
		$reflection = new \ReflectionClass( NotificationDispatcher::class );

		foreach ( $reflection->getConstructor()->getParameters() as $parameter ) {
			$type = $parameter->getType();
			$this->assertNotNull( $type );
			$type_name = $type instanceof \ReflectionNamedType ? $type->getName() : (string) $type;
			$this->assertNotSame( \UniversalTelegram\Queue\JobEnvelope::class, $type_name );
			$this->assertNotSame( \UniversalTelegram\Queue\Dispatcher::class, $type_name );
		}
	}
}
