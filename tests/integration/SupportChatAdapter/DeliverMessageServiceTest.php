<?php
/**
 * Integration tests for outbound Contract acceptors.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Tests\Integration\SupportChatAdapter;

use UniversalTelegram\Audit\AuditLogger;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Privacy\Redactor;
use UniversalTelegram\Queue\DeliveryClass;
use UniversalTelegram\Queue\Dispatcher;
use UniversalTelegram\SupportChatAdapter\ChannelBindingRepository;
use UniversalTelegram\SupportChatAdapter\DeliveryIdempotencyRepository;
use UniversalTelegram\SupportChatAdapter\Outbound\DeliverMessageService;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Telegram\Outbound\OutboundMessageRepository;
use UniversalTelegram\Tests\Integration\Support\SpyExpeditedDispatchTrigger;
use WP_UnitTestCase;

/**
 * @covers \UniversalTelegram\SupportChatAdapter\Outbound\DeliverMessageService
 */
final class DeliverMessageServiceTest extends WP_UnitTestCase {

	private ChannelBindingRepository $bindings;
	private DeliveryIdempotencyRepository $keys;
	private OutboundMessageRepository $messages;
	private SpyExpeditedDispatchTrigger $expedited;
	private DeliverMessageService $service;

	public function set_up(): void {
		parent::set_up();

		$schema          = new SchemaHealth();
		$this->bindings  = new ChannelBindingRepository( $schema );
		$this->keys      = new DeliveryIdempotencyRepository( $schema );
		$this->messages  = new OutboundMessageRepository( $schema, new CredentialVault() );
		$this->expedited = new SpyExpeditedDispatchTrigger( new AuditLogger( $schema, new Redactor() ) );
		$this->service   = new DeliverMessageService( $this->bindings, $this->keys, $this->messages, new Dispatcher( $schema ), $this->expedited );
	}

	private function active_binding( string $suffix ): string {
		$binding = $this->bindings->create(
			wp_generate_uuid4(),
			wp_generate_uuid4(),
			'ensure-' . $suffix,
			1,
			1,
			42
		);
		$this->assertNotNull( $binding );

		return $binding->support_conversation_uuid();
	}

	public function test_idempotency_reuses_key_regardless_of_delivery_class(): void {
		$conv = $this->active_binding( '01' );

		$first  = $this->service->deliver( $conv, 'key-1', 'hello', 'Visitor', DeliveryClass::INTERACTIVE_CHAT );
		$second = $this->service->deliver( $conv, 'key-1', 'hello again', 'Visitor', DeliveryClass::STANDARD );

		$this->assertTrue( $first['ok'] );
		$this->assertTrue( $second['ok'] );
		$this->assertTrue( $second['reused'] );
	}

	public function test_interactive_chat_persists_the_class_on_the_row(): void {
		global $wpdb;
		$conv = $this->active_binding( '02' );

		$result = $this->service->deliver( $conv, 'key-2', 'ping', 'Visitor', DeliveryClass::INTERACTIVE_CHAT );
		$this->assertTrue( $result['ok'] );

		$table = $wpdb->prefix . \UniversalTelegram\Persistence\Migrator::OUTBOUND_MESSAGES_TABLE;
		$class = $wpdb->get_var( "SELECT delivery_class FROM {$table} ORDER BY id DESC LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->assertSame( 'interactive_chat', $class );

		$row = $this->messages->find_by_uuid( (string) $wpdb->get_var( "SELECT message_uuid FROM {$table} ORDER BY id DESC LIMIT 1" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->assertSame( 'interactive_chat', $row->delivery_class() );
	}

	public function test_standard_and_default_persist_standard(): void {
		global $wpdb;
		$conv  = $this->active_binding( '03' );
		$table = $wpdb->prefix . \UniversalTelegram\Persistence\Migrator::OUTBOUND_MESSAGES_TABLE;

		$this->service->deliver( $conv, 'key-3a', 'a', 'Visitor' );
		$this->assertSame( 'standard', $wpdb->get_var( "SELECT delivery_class FROM {$table} ORDER BY id DESC LIMIT 1" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$this->service->deliver( $conv, 'key-3b', 'b', 'Visitor', DeliveryClass::STANDARD );
		$this->assertSame( 'standard', $wpdb->get_var( "SELECT delivery_class FROM {$table} ORDER BY id DESC LIMIT 1" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	public function test_expedited_trigger_fires_only_for_interactive_chat(): void {
		$conv = $this->active_binding( '04' );

		$this->service->deliver( $conv, 'key-4a', 'ordinary', 'Visitor', DeliveryClass::STANDARD );
		$this->assertSame( 0, $this->expedited->calls, 'standard delivery must not nudge the queue runner' );

		$this->service->deliver( $conv, 'key-4b', 'interactive', 'Visitor', DeliveryClass::INTERACTIVE_CHAT );
		$this->assertSame( 1, $this->expedited->calls, 'interactive delivery fires the ADR-0023 trigger exactly once' );
	}

	public function test_a_poisoned_class_argument_is_coerced_to_standard_never_rejected_here(): void {
		global $wpdb;
		$conv  = $this->active_binding( '05' );
		$table = $wpdb->prefix . \UniversalTelegram\Persistence\Migrator::OUTBOUND_MESSAGES_TABLE;

		// The wire is validated at the controller; this is the belt-and-braces
		// repository-level defence (docs/adr/0045 §4).
		$result = $this->service->deliver( $conv, 'key-5', 'x', 'Visitor', 'garbage' );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'standard', $wpdb->get_var( "SELECT delivery_class FROM {$table} ORDER BY id DESC LIMIT 1" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->assertSame( 0, $this->expedited->calls );
	}

	public function test_service_still_works_without_an_injected_trigger(): void {
		$conv    = $this->active_binding( '06' );
		$service = new DeliverMessageService( $this->bindings, $this->keys, $this->messages, new Dispatcher( new SchemaHealth() ) );

		$result = $service->deliver( $conv, 'key-6', 'x', 'Visitor', DeliveryClass::INTERACTIVE_CHAT );

		$this->assertTrue( $result['ok'] );
	}
}
