<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Telegram\Inbound;

use UniversalTelegram\Audit\AuditLogger;
use UniversalTelegram\Conversations\ChatProfileResolver;
use UniversalTelegram\Conversations\ConversationRepository;
use UniversalTelegram\Conversations\OperatorAvailabilityRepository;
use UniversalTelegram\Conversations\OperatorIdentityRepository;
use UniversalTelegram\Conversations\VisitorTokenGenerator;
use UniversalTelegram\Conversations\MessageRepository;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Events\EventHistoryRepository;
use UniversalTelegram\Events\Registry;
use UniversalTelegram\Integrations\WooCommerce\WooCommerceCommandQueryService;
use UniversalTelegram\Integrations\WooCommerce\WooCommerceSupport;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Privacy\Redactor;
use UniversalTelegram\Queue\Dispatcher;
use UniversalTelegram\Queue\QueueHealth;
use UniversalTelegram\Telegram\Commands\BotCommandDispatcher;
use UniversalTelegram\Telegram\Commands\ConfirmationStore;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;
use UniversalTelegram\Telegram\Configuration\DestinationRepository;
use UniversalTelegram\Telegram\Inbound\UpdateRepository;
use UniversalTelegram\Telegram\Inbound\WebhookController;
use UniversalTelegram\Telegram\Inbound\WebhookSecretVerifier;
use UniversalTelegram\Telegram\Outbound\MessageDispatcher;
use UniversalTelegram\Telegram\Outbound\OutboundMessageRepository;
use WP_REST_Request;
use WP_UnitTestCase;

final class WebhookControllerTest extends WP_UnitTestCase {

	/**
	 * @var BotProfileRepository
	 */
	private BotProfileRepository $bots;

	/**
	 * @var UpdateRepository
	 */
	private UpdateRepository $updates;

	/**
	 * @var ConversationRepository
	 */
	private ConversationRepository $conversations;

	/**
	 * @var DestinationRepository
	 */
	private DestinationRepository $destinations;

	/**
	 * @var WebhookController
	 */
	private WebhookController $controller;

	protected function setUp(): void {
		parent::setUp();

		$schema_health = new SchemaHealth();
		$vault         = new CredentialVault();
		$audit_logger  = new AuditLogger( $schema_health, new Redactor() );

		$this->bots          = new BotProfileRepository( $schema_health, $vault );
		$this->updates       = new UpdateRepository( $schema_health );
		$this->conversations = new ConversationRepository( $schema_health, new CredentialVault(), new VisitorTokenGenerator() );
		$this->destinations  = new DestinationRepository( $schema_health );
		$messages            = new MessageRepository( $schema_health, $vault );
		$verifier            = new WebhookSecretVerifier( $this->bots, $audit_logger );

		$outbound_messages  = new OutboundMessageRepository( $schema_health, $vault );
		$message_dispatcher = new MessageDispatcher( $outbound_messages, new Dispatcher( $schema_health ) );
		$bot_commands       = new BotCommandDispatcher(
			new OperatorIdentityRepository( $schema_health ),
			$this->conversations,
			new ChatProfileResolver( $this->bots, $this->destinations ),
			new OperatorAvailabilityRepository( $schema_health ),
			new QueueHealth(),
			new EventHistoryRepository( $schema_health, new Registry(), new Redactor() ),
			new WooCommerceSupport(),
			new WooCommerceCommandQueryService(),
			new ConfirmationStore(),
			$message_dispatcher,
			$audit_logger
		);

		$this->controller = new WebhookController(
			$schema_health,
			$this->bots,
			$verifier,
			$this->updates,
			$this->conversations,
			$messages,
			new ChatProfileResolver( $this->bots, $this->destinations ),
			new OperatorIdentityRepository( $schema_health ),
			$audit_logger,
			$bot_commands
		);
	}

	private function request_for( string $bot_uuid, ?string $secret, string $body ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/universal-telegram/v1/webhook/' . $bot_uuid );
		$request->set_url_params( array( 'bot_uuid' => $bot_uuid ) );

		if ( null !== $secret ) {
			$request->set_header( 'X-Telegram-Bot-Api-Secret-Token', $secret );
		}

		$request->set_body( $body );

		return $request;
	}

	public function test_missing_header_produces_generic_401(): void {
		$bot = $this->bots->create( 'Bot', 'token' );

		$response = $this->controller->handle_request( $this->request_for( $bot->bot_uuid(), null, '{}' ) );

		$this->assertSame( 401, $response->get_status() );
	}

	public function test_wrong_header_value_produces_generic_401(): void {
		$bot = $this->bots->create( 'Bot', 'token' );

		$response = $this->controller->handle_request( $this->request_for( $bot->bot_uuid(), 'wrong-secret', '{}' ) );

		$this->assertSame( 401, $response->get_status() );
	}

	public function test_unknown_bot_uuid_produces_generic_401(): void {
		$response = $this->controller->handle_request(
			$this->request_for( '11111111-1111-1111-1111-111111111111', 'anything', '{}' )
		);

		$this->assertSame( 401, $response->get_status() );
	}

	private function active_secret_for( \UniversalTelegram\Telegram\Configuration\BotProfile $bot ): string {
		return $this->bots->decrypt_webhook_secret( $bot )->plaintext();
	}

	public function test_a_valid_novel_update_is_acknowledged_and_recorded_exactly_once(): void {
		$bot    = $this->bots->create( 'Bot', 'token' );
		$secret = $this->active_secret_for( $bot );

		$body = wp_json_encode(
			array(
				'update_id' => 100,
				'message'   => array( 'chat' => array( 'id' => 555 ) ),
			)
		);

		$response = $this->controller->handle_request( $this->request_for( $bot->bot_uuid(), $secret, $body ) );
		$this->assertSame( 200, $response->get_status() );

		global $wpdb;
		$table = $wpdb->prefix . 'universal_telegram_inbound_updates';
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE bot_id = {$bot->id()} AND update_id = 100" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertSame( 1, $count );
	}

	public function test_a_replayed_update_is_acknowledged_but_not_recorded_twice(): void {
		$bot    = $this->bots->create( 'Bot', 'token' );
		$secret = $this->active_secret_for( $bot );

		$body = wp_json_encode(
			array(
				'update_id' => 200,
				'message'   => array( 'chat' => array( 'id' => 555 ) ),
			)
		);

		$first  = $this->controller->handle_request( $this->request_for( $bot->bot_uuid(), $secret, $body ) );
		$second = $this->controller->handle_request( $this->request_for( $bot->bot_uuid(), $secret, $body ) );

		$this->assertSame( 200, $first->get_status() );
		$this->assertSame( 200, $second->get_status() );

		global $wpdb;
		$table = $wpdb->prefix . 'universal_telegram_inbound_updates';
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE bot_id = {$bot->id()} AND update_id = 200" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertSame( 1, $count );
	}

	public function test_malformed_json_produces_400(): void {
		$bot    = $this->bots->create( 'Bot', 'token' );
		$secret = $this->active_secret_for( $bot );

		$response = $this->controller->handle_request( $this->request_for( $bot->bot_uuid(), $secret, 'not json at all {{{' ) );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_missing_update_id_produces_400(): void {
		$bot    = $this->bots->create( 'Bot', 'token' );
		$secret = $this->active_secret_for( $bot );

		$response = $this->controller->handle_request( $this->request_for( $bot->bot_uuid(), $secret, wp_json_encode( array( 'message' => array() ) ) ) );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_oversized_body_produces_413_before_json_decoding(): void {
		$bot    = $this->bots->create( 'Bot', 'token' );
		$secret = $this->active_secret_for( $bot );

		$oversized_schema_health = new SchemaHealth();
		$oversized_audit         = new AuditLogger( $oversized_schema_health, new Redactor() );
		$oversized_bot_commands  = new BotCommandDispatcher(
			new OperatorIdentityRepository( $oversized_schema_health ),
			$this->conversations,
			new ChatProfileResolver( $this->bots, $this->destinations ),
			new OperatorAvailabilityRepository( $oversized_schema_health ),
			new QueueHealth(),
			new EventHistoryRepository( $oversized_schema_health, new Registry(), new Redactor() ),
			new WooCommerceSupport(),
			new WooCommerceCommandQueryService(),
			new ConfirmationStore(),
			new MessageDispatcher( new OutboundMessageRepository( $oversized_schema_health, new CredentialVault() ), new Dispatcher( $oversized_schema_health ) ),
			$oversized_audit
		);

		$controller = new WebhookController(
			$oversized_schema_health,
			$this->bots,
			new WebhookSecretVerifier( $this->bots, $oversized_audit ),
			$this->updates,
			$this->conversations,
			new MessageRepository( $oversized_schema_health, new CredentialVault() ),
			new ChatProfileResolver( $this->bots, $this->destinations ),
			new OperatorIdentityRepository( $oversized_schema_health ),
			$oversized_audit,
			$oversized_bot_commands,
			10
		);

		$response = $controller->handle_request( $this->request_for( $bot->bot_uuid(), $secret, str_repeat( 'a', 100 ) ) );

		$this->assertSame( 413, $response->get_status() );
	}

	public function test_unsupported_update_type_is_still_deduplicated_and_acknowledged(): void {
		$bot    = $this->bots->create( 'Bot', 'token' );
		$secret = $this->active_secret_for( $bot );

		$body = wp_json_encode(
			array(
				'update_id'   => 300,
				'poll_answer' => array( 'poll_id' => 'abc' ),
			)
		);

		$response = $this->controller->handle_request( $this->request_for( $bot->bot_uuid(), $secret, $body ) );
		$this->assertSame( 200, $response->get_status() );

		global $wpdb;
		$table = $wpdb->prefix . 'universal_telegram_inbound_updates';
		$type  = $wpdb->get_var( "SELECT update_type FROM {$table} WHERE bot_id = {$bot->id()} AND update_id = 300" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertSame( 'unsupported', $type );
	}

	public function test_forum_topic_metadata_is_captured_for_a_supergroup_message(): void {
		$bot    = $this->bots->create( 'Bot', 'token' );
		$secret = $this->active_secret_for( $bot );

		$body = wp_json_encode(
			array(
				'update_id' => 400,
				'message'   => array(
					'chat'              => array( 'id' => -100123 ),
					'message_thread_id' => 77,
				),
			)
		);

		$response = $this->controller->handle_request( $this->request_for( $bot->bot_uuid(), $secret, $body ) );
		$this->assertSame( 200, $response->get_status() );

		global $wpdb;
		$table = $wpdb->prefix . 'universal_telegram_inbound_updates';
		$row   = $wpdb->get_row( "SELECT * FROM {$table} WHERE bot_id = {$bot->id()} AND update_id = 400", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$this->assertSame( '-100123', $row['chat_id'] );
		$this->assertSame( '77', $row['message_thread_id'] );
	}

	public function test_degraded_schema_returns_503_without_attempting_an_insert(): void {
		$degraded = new SchemaHealth();
		$degraded->mark_unavailable( \UniversalTelegram\Persistence\MigrationFailureCode::STEP_FAILED );

		$bots                   = new BotProfileRepository( $degraded, new CredentialVault() );
		$updates                = new UpdateRepository( $degraded );
		$degraded_audit         = new AuditLogger( $degraded, new Redactor() );
		$verifier               = new WebhookSecretVerifier( $bots, $degraded_audit );
		$degraded_conversations = new ConversationRepository( $degraded, new CredentialVault(), new VisitorTokenGenerator() );
		$degraded_bot_commands  = new BotCommandDispatcher(
			new OperatorIdentityRepository( $degraded ),
			$degraded_conversations,
			new ChatProfileResolver( $bots, new DestinationRepository( $degraded ) ),
			new OperatorAvailabilityRepository( $degraded ),
			new QueueHealth(),
			new EventHistoryRepository( $degraded, new Registry(), new Redactor() ),
			new WooCommerceSupport(),
			new WooCommerceCommandQueryService(),
			new ConfirmationStore(),
			new MessageDispatcher( new OutboundMessageRepository( $degraded, new CredentialVault() ), new Dispatcher( $degraded ) ),
			$degraded_audit
		);

		$controller = new WebhookController(
			$degraded,
			$bots,
			$verifier,
			$updates,
			$degraded_conversations,
			new MessageRepository( $degraded, new CredentialVault() ),
			new ChatProfileResolver( $bots, new DestinationRepository( $degraded ) ),
			new OperatorIdentityRepository( $degraded ),
			$degraded_audit,
			$degraded_bot_commands
		);

		$response = $controller->handle_request( $this->request_for( 'anything', 'anything', '{}' ) );

		$this->assertSame( 503, $response->get_status() );
	}
}
