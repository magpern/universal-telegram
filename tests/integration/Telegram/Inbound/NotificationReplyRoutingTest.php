<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Telegram\Inbound;

use UniversalTelegram\Audit\AuditLogger;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Events\EventHistoryRepository;
use UniversalTelegram\Events\Registry;
use UniversalTelegram\Integrations\WooCommerce\WooCommerceCommandQueryService;
use UniversalTelegram\Integrations\WooCommerce\WooCommerceSupport;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Privacy\Redactor;
use UniversalTelegram\Queue\Dispatcher;
use UniversalTelegram\Queue\QueueHealth;
use UniversalTelegram\Queue\RetryPolicy;
use UniversalTelegram\SupportChatAdapter\Identity\OperatorIdentityMapRepository;
use UniversalTelegram\Tests\Integration\Support\RecordingReplyHandler;
use UniversalTelegram\Telegram\Client\TelegramApiClient;
use UniversalTelegram\Telegram\Client\TelegramFailureClassifier;
use UniversalTelegram\Telegram\Commands\BotCommandDispatcher;
use UniversalTelegram\Telegram\Configuration\BotProfile;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;
use UniversalTelegram\Telegram\Configuration\Destination;
use UniversalTelegram\Telegram\Configuration\DestinationKind;
use UniversalTelegram\Telegram\Configuration\DestinationRepository;
use UniversalTelegram\Telegram\Inbound\NotificationReplyRouter;
use UniversalTelegram\Telegram\Inbound\UpdateRepository;
use UniversalTelegram\Telegram\Inbound\WebhookController;
use UniversalTelegram\Telegram\Inbound\WebhookSecretVerifier;
use UniversalTelegram\Telegram\Outbound\MessageDispatcher;
use UniversalTelegram\Telegram\Outbound\OutboundMessage;
use UniversalTelegram\Telegram\Outbound\OutboundMessageRepository;
use UniversalTelegram\Telegram\Outbound\SendMessageHandler;
use UniversalTelegram\Telegram\Outbound\UnresolvedOutboundAbandoner;
use UniversalTelegram\Telegram\Reliability\CircuitBreaker;
use UniversalTelegram\Telegram\Reliability\RateLimiter;
use WP_UnitTestCase;

/**
 * A native reply (docs/adr/0046) is routed only after the update dedup guard, resolves the
 * destination by the (bot, chat, thread) triple and the notification by (destination,
 * replied-to message id), and everything else falls through unchanged.
 */
final class NotificationReplyRoutingTest extends WP_UnitTestCase {

	private SchemaHealth $schema_health;
	private BotProfileRepository $bots;
	private DestinationRepository $destinations;
	private OutboundMessageRepository $messages;
	private RecordingReplyHandler $handler;
	private NotificationReplyRouter $router;
	private BotProfile $bot;

	protected function setUp(): void {
		parent::setUp();

		$this->schema_health = new SchemaHealth();
		$vault               = new CredentialVault();
		$this->bots          = new BotProfileRepository( $this->schema_health, $vault );
		$this->destinations  = new DestinationRepository( $this->schema_health );
		$this->messages      = new OutboundMessageRepository( $this->schema_health, $vault );
		$this->handler       = new RecordingReplyHandler();
		$this->router        = new NotificationReplyRouter( $this->destinations, $this->messages );
		$this->router->register_handler( 'ticket', $this->handler );

		$created = $this->bots->create( 'Bot', 'token' );
		$this->bots->update_telegram_identity( $created->id(), 123456, 'TestBot' );
		$this->bot = $this->bots->find( $created->id() );
	}

	private function notification( Destination $destination, int $telegram_message_id, ?string $token ): OutboundMessage {
		$message = $this->messages->create( $this->bot->id(), $destination->id(), 'n', null, 'standard', null, $token );
		$this->messages->mark_sent( $message->id(), $telegram_message_id );

		return $message;
	}

	/**
	 * Builds a `message` update replying to a notification.
	 *
	 * @param int                  $update_id         Telegram update id.
	 * @param string               $chat_id           Chat id.
	 * @param int|null             $thread            Forum topic id.
	 * @param int                  $replied_to        Replied-to Telegram message id.
	 * @param array<string, mixed> $message_overrides Message keys to override.
	 *
	 * @return array<string, mixed>
	 */
	private function reply_update( int $update_id, string $chat_id, ?int $thread, int $replied_to, array $message_overrides = array() ): array {
		$message = array_merge(
			array(
				'message_id'       => 900 + $update_id,
				'chat'             => array( 'id' => (int) $chat_id ),
				'from'             => array( 'id' => 555 ),
				'text'             => 'On its way',
				'reply_to_message' => array( 'message_id' => $replied_to ),
			),
			$message_overrides
		);

		if ( null !== $thread ) {
			$message['message_thread_id'] = $thread;
		}

		return array(
			'update_id' => $update_id,
			'message'   => $message,
		);
	}

	public function test_a_reply_to_a_correlated_notification_reaches_the_handler_with_the_ticket_suffix(): void {
		$destination = $this->destinations->create( $this->bot->id(), DestinationKind::SUPERGROUP, '-1001', 11, 'Support' );
		$this->notification( $destination, 700, 'ticket:42' );

		$update = $this->reply_update( 1, '-1001', 11, 700 );

		$this->assertTrue( $this->router->try_handle( $this->bot, '-1001', 11, $update ) );
		$this->assertCount( 1, $this->handler->calls );
		$this->assertSame( array( $this->bot->id(), $destination->id(), '42' ), array_slice( $this->handler->calls[0], 0, 3 ) );
		$this->assertSame( 'On its way', $this->handler->calls[0][3]['text'] );
	}

	public function test_two_topics_with_the_same_telegram_message_id_route_to_their_own_ticket(): void {
		$topic_a = $this->destinations->create( $this->bot->id(), DestinationKind::SUPERGROUP, '-1001', 11, 'A' );
		$topic_b = $this->destinations->create( $this->bot->id(), DestinationKind::SUPERGROUP, '-1001', 12, 'B' );
		$this->notification( $topic_a, 5, 'ticket:1' );
		$this->notification( $topic_b, 5, 'ticket:2' );

		$this->router->try_handle( $this->bot, '-1001', 12, $this->reply_update( 1, '-1001', 12, 5 ) );
		$this->router->try_handle( $this->bot, '-1001', 11, $this->reply_update( 2, '-1001', 11, 5 ) );

		$this->assertSame( array( '2', '1' ), array( $this->handler->calls[0][2], $this->handler->calls[1][2] ) );
		$this->assertSame( $topic_b->id(), $this->handler->calls[0][1] );
	}

	public function test_an_unrelated_reply_falls_through(): void {
		$destination = $this->destinations->create( $this->bot->id(), DestinationKind::SUPERGROUP, '-1001', 11, 'Support' );
		$this->notification( $destination, 700, 'ticket:42' );

		$this->assertFalse( $this->router->try_handle( $this->bot, '-1001', 11, $this->reply_update( 1, '-1001', 11, 701 ) ) );
		$this->assertSame( array(), $this->handler->calls );
	}

	public function test_a_message_that_is_not_a_reply_falls_through(): void {
		$destination = $this->destinations->create( $this->bot->id(), DestinationKind::SUPERGROUP, '-1001', 11, 'Support' );
		$this->notification( $destination, 700, 'ticket:42' );

		$update = $this->reply_update( 1, '-1001', 11, 700 );
		unset( $update['message']['reply_to_message'] );

		$this->assertFalse( $this->router->try_handle( $this->bot, '-1001', 11, $update ) );
	}

	public function test_a_notification_without_a_token_falls_through(): void {
		$destination = $this->destinations->create( $this->bot->id(), DestinationKind::SUPERGROUP, '-1001', 11, 'Support' );
		$this->notification( $destination, 700, null );

		$this->assertFalse( $this->router->try_handle( $this->bot, '-1001', 11, $this->reply_update( 1, '-1001', 11, 700 ) ) );
	}

	public function test_an_unknown_token_prefix_falls_through(): void {
		$destination = $this->destinations->create( $this->bot->id(), DestinationKind::SUPERGROUP, '-1001', 11, 'Support' );
		$this->notification( $destination, 700, 'order:9' );

		$this->assertFalse( $this->router->try_handle( $this->bot, '-1001', 11, $this->reply_update( 1, '-1001', 11, 700 ) ) );
	}

	public function test_a_reply_in_a_topic_without_a_destination_falls_through(): void {
		$destination = $this->destinations->create( $this->bot->id(), DestinationKind::SUPERGROUP, '-1001', 11, 'Support' );
		$this->notification( $destination, 700, 'ticket:42' );

		$this->assertFalse( $this->router->try_handle( $this->bot, '-1001', 99, $this->reply_update( 1, '-1001', 99, 700 ) ) );
		$this->assertFalse( $this->router->try_handle( $this->bot, '-1001', null, $this->reply_update( 1, '-1001', null, 700 ) ) );
	}

	public function test_a_bot_command_typed_as_a_reply_is_left_to_command_dispatch(): void {
		$destination = $this->destinations->create( $this->bot->id(), DestinationKind::SUPERGROUP, '-1001', 11, 'Support' );
		$this->notification( $destination, 700, 'ticket:42' );

		$update = $this->reply_update(
			1,
			'-1001',
			11,
			700,
			array(
				'text'     => '/whoami',
				'entities' => array(
					array(
						'type'   => 'bot_command',
						'offset' => 0,
						'length' => 7,
					),
				),
			)
		);

		$this->assertFalse( $this->router->try_handle( $this->bot, '-1001', 11, $update ) );
		$this->assertSame( array(), $this->handler->calls );
	}

	public function test_a_router_without_handlers_is_inert(): void {
		$router      = new NotificationReplyRouter( $this->destinations, $this->messages );
		$destination = $this->destinations->create( $this->bot->id(), DestinationKind::SUPERGROUP, '-1001', 11, 'Support' );
		$this->notification( $destination, 700, 'ticket:42' );

		$this->assertFalse( $router->try_handle( $this->bot, '-1001', 11, $this->reply_update( 1, '-1001', 11, 700 ) ) );
	}

	public function test_another_bots_notification_is_never_claimed(): void {
		$other      = $this->bots->create( 'Other', 'token2' );
		$other_dest = $this->destinations->create( $other->id(), DestinationKind::PRIVATE, '555', null, 'DM' );
		$message    = $this->messages->create( $other->id(), $other_dest->id(), 'n', null, 'standard', null, 'ticket:7' );
		$this->messages->mark_sent( $message->id(), 40 );

		// The receiving bot has a destination with the same chat but the message belongs to the other bot's destination.
		$this->destinations->create( $this->bot->id(), DestinationKind::PRIVATE, '555', null, 'DM' );

		$this->assertFalse( $this->router->try_handle( $this->bot, '555', null, $this->reply_update( 1, '555', null, 40 ) ) );
	}

	private function controller(): WebhookController {
		$vault = new CredentialVault();
		$audit = new AuditLogger( $this->schema_health, new Redactor() );

		$send_handler = new SendMessageHandler(
			$this->messages,
			$this->bots,
			$this->destinations,
			new TelegramApiClient(),
			new TelegramFailureClassifier(),
			new RateLimiter( $this->schema_health ),
			new CircuitBreaker( $this->schema_health, new RetryPolicy() ),
			$audit,
			new RetryPolicy(),
			new UnresolvedOutboundAbandoner( $this->messages )
		);

		$commands = new BotCommandDispatcher(
			new OperatorIdentityMapRepository( $this->schema_health ),
			new QueueHealth(),
			new EventHistoryRepository( $this->schema_health, new Registry(), new Redactor() ),
			new WooCommerceSupport(),
			new WooCommerceCommandQueryService(),
			new MessageDispatcher( $this->messages, new Dispatcher( $this->schema_health ), $send_handler, $this->bots, $this->destinations ),
			$this->destinations,
			$audit
		);

		return new WebhookController(
			$this->schema_health,
			$this->bots,
			new WebhookSecretVerifier( $this->bots, $audit ),
			new UpdateRepository( $this->schema_health ),
			$commands,
			1048576,
			null,
			null,
			null,
			null,
			$this->router
		);
	}

	public function test_the_controller_routes_a_reply_exactly_once_even_if_telegram_redelivers_the_update(): void {
		$destination = $this->destinations->create( $this->bot->id(), DestinationKind::SUPERGROUP, '-1001', 11, 'Support' );
		$this->notification( $destination, 700, 'ticket:42' );

		$controller = $this->controller();
		$update     = $this->reply_update( 4242, '-1001', 11, 700 );

		$controller->process_update( $this->bot, $update );
		$controller->process_update( $this->bot, $update );
		$controller->process_update( $this->bot, $update );

		$this->assertCount( 1, $this->handler->calls, 'a redelivered update_id must never reach the handler twice' );
	}

	public function test_the_controller_still_ignores_an_unrelated_reply_without_touching_the_handler(): void {
		$destination = $this->destinations->create( $this->bot->id(), DestinationKind::SUPERGROUP, '-1001', 11, 'Support' );
		$this->notification( $destination, 700, 'ticket:42' );

		$this->controller()->process_update( $this->bot, $this->reply_update( 9, '-1001', 11, 12345 ) );

		$this->assertSame( array(), $this->handler->calls );
	}
}
