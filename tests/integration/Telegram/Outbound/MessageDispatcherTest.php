<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Telegram\Outbound;

use UniversalTelegram\Audit\AuditLogger;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Privacy\Redactor;
use UniversalTelegram\Queue\DispatchState;
use UniversalTelegram\Queue\RetryPolicy;
use UniversalTelegram\Telegram\Client\TelegramApiClient;
use UniversalTelegram\Telegram\Client\TelegramFailureClassifier;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;
use UniversalTelegram\Telegram\Configuration\DestinationKind;
use UniversalTelegram\Telegram\Configuration\DestinationRepository;
use UniversalTelegram\Telegram\Outbound\MessageDispatcher;
use UniversalTelegram\Telegram\Outbound\OutboundMessageRepository;
use UniversalTelegram\Telegram\Outbound\OutboundMessageStatus;
use UniversalTelegram\Telegram\Outbound\SendMessageHandler;
use UniversalTelegram\Telegram\Outbound\UnresolvedOutboundAbandoner;
use UniversalTelegram\Telegram\Reliability\CircuitBreaker;
use UniversalTelegram\Telegram\Reliability\RateLimiter;
use UniversalTelegram\Queue\Dispatcher;
use WP_UnitTestCase;

final class MessageDispatcherTest extends WP_UnitTestCase {

	/**
	 * @var array<int, callable>
	 */
	private array $filters_to_remove = array();

	protected function tearDown(): void {
		foreach ( $this->filters_to_remove as $callback ) {
			remove_filter( 'pre_http_request', $callback );
		}
		$this->filters_to_remove = array();

		parent::tearDown();
	}

	private function fake_response( int $status, array $body ): void {
		$callback = static function () use ( $status, $body ) {
			return array(
				'response' => array( 'code' => $status ),
				'body'     => wp_json_encode( $body ),
			);
		};
		add_filter( 'pre_http_request', $callback, 10, 0 );
		$this->filters_to_remove[] = $callback;
	}

	private function send_message_handler( SchemaHealth $schema_health, OutboundMessageRepository $messages, BotProfileRepository $bots, DestinationRepository $destinations ): SendMessageHandler {
		return new SendMessageHandler(
			$messages,
			$bots,
			$destinations,
			new TelegramApiClient(),
			new TelegramFailureClassifier(),
			new RateLimiter( $schema_health ),
			new CircuitBreaker( $schema_health, new RetryPolicy() ),
			new AuditLogger( $schema_health, new Redactor() ),
			new RetryPolicy(),
			new UnresolvedOutboundAbandoner( $messages )
		);
	}

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

	public function test_attempt_immediate_false_leaves_the_message_pending_even_with_an_immediate_handler_wired(): void {
		$this->fake_response(
			200,
			array(
				'ok'     => true,
				'result' => array( 'message_id' => 123 ),
			)
		);

		$schema_health = new SchemaHealth();
		$vault         = new CredentialVault();

		$bots         = new BotProfileRepository( $schema_health, $vault );
		$destinations = new DestinationRepository( $schema_health );
		$messages     = new OutboundMessageRepository( $schema_health, $vault );

		$bot         = $bots->create( 'Bot', 'token' );
		$destination = $destinations->create( $bot->id(), DestinationKind::PRIVATE, '123', null, 'Chat' );

		$dispatcher = new MessageDispatcher(
			$messages,
			new Dispatcher( $schema_health ),
			$this->send_message_handler( $schema_health, $messages, $bots, $destinations ),
			$bots,
			$destinations
		);

		$dispatcher->send( $bot->id(), $destination->id(), 'hi', null, null, false );

		global $wpdb;
		$table  = $wpdb->prefix . 'universal_telegram_outbound_messages';
		$status = $wpdb->get_var( "SELECT status FROM {$table} WHERE bot_id = {$bot->id()}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$this->assertSame( OutboundMessageStatus::PENDING->value, $status, 'no immediate attempt was made -- the durable queue worker owns delivery' );
	}

	public function test_attempt_immediate_true_delivers_synchronously_before_send_returns(): void {
		$this->fake_response(
			200,
			array(
				'ok'     => true,
				'result' => array( 'message_id' => 456 ),
			)
		);

		$schema_health = new SchemaHealth();
		$vault         = new CredentialVault();

		$bots         = new BotProfileRepository( $schema_health, $vault );
		$destinations = new DestinationRepository( $schema_health );
		$messages     = new OutboundMessageRepository( $schema_health, $vault );

		$bot         = $bots->create( 'Bot', 'token' );
		$destination = $destinations->create( $bot->id(), DestinationKind::PRIVATE, '123', null, 'Chat' );

		$dispatcher = new MessageDispatcher(
			$messages,
			new Dispatcher( $schema_health ),
			$this->send_message_handler( $schema_health, $messages, $bots, $destinations ),
			$bots,
			$destinations
		);

		$dispatcher->send( $bot->id(), $destination->id(), 'hi', null, null, true );

		global $wpdb;
		$table = $wpdb->prefix . 'universal_telegram_outbound_messages';
		$row   = $wpdb->get_row( "SELECT status, telegram_message_id FROM {$table} WHERE bot_id = {$bot->id()}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$this->assertSame( OutboundMessageStatus::SENT->value, $row['status'] );
		$this->assertSame( '456', (string) $row['telegram_message_id'] );
	}

	public function test_attempt_immediate_true_without_a_wired_handler_is_a_safe_no_op(): void {
		$schema_health = new SchemaHealth();
		$vault         = new CredentialVault();

		$bots         = new BotProfileRepository( $schema_health, $vault );
		$destinations = new DestinationRepository( $schema_health );
		$messages     = new OutboundMessageRepository( $schema_health, $vault );

		$bot         = $bots->create( 'Bot', 'token' );
		$destination = $destinations->create( $bot->id(), DestinationKind::PRIVATE, '123', null, 'Chat' );

		// No SendMessageHandler/bots/destinations wired -- matches every
		// pre-M09 caller/test double.
		$dispatcher = new MessageDispatcher( $messages, new Dispatcher( $schema_health ) );

		$result = $dispatcher->send( $bot->id(), $destination->id(), 'hi', null, null, true );

		$this->assertNotNull( $result );
		$this->assertSame( DispatchState::SCHEDULED, $result->state() );

		global $wpdb;
		$table  = $wpdb->prefix . 'universal_telegram_outbound_messages';
		$status = $wpdb->get_var( "SELECT status FROM {$table} WHERE bot_id = {$bot->id()}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$this->assertSame( OutboundMessageStatus::PENDING->value, $status );
	}
}
