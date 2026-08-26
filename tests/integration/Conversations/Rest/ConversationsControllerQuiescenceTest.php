<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Conversations\Rest;

use UniversalTelegram\AI\Config\AIProviderRepository;
use UniversalTelegram\Audit\AuditLogger;
use UniversalTelegram\Conversations\ChatProfileResolver;
use UniversalTelegram\Conversations\ConversationOutboundDispatcher;
use UniversalTelegram\Conversations\ConversationOutboundHandler;
use UniversalTelegram\Conversations\ConversationRepository;
use UniversalTelegram\Conversations\ImmediateDeliveryAttempt;
use UniversalTelegram\Conversations\MessageRepository;
use UniversalTelegram\Conversations\PromptDeliveryFallback;
use UniversalTelegram\Conversations\Rest\ConversationsController;
use UniversalTelegram\Conversations\TopicCreationDispatcher;
use UniversalTelegram\Conversations\VisitorTokenGenerator;
use UniversalTelegram\Core\Configuration\Settings;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Migration\DeferredUpdateRepository;
use UniversalTelegram\Migration\QuiescenceGate;
use UniversalTelegram\Migration\QuiescenceTransitionRepository;
use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Privacy\Redactor;
use UniversalTelegram\Queue\Dispatcher;
use UniversalTelegram\Queue\RetryPolicy;
use UniversalTelegram\Telegram\Client\TelegramFailureClassifier;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;
use UniversalTelegram\Telegram\Configuration\DestinationRepository;
use UniversalTelegram\Telegram\Outbound\OutboundMessageRepository;
use UniversalTelegram\Telegram\Reliability\CircuitBreaker;
use UniversalTelegram\Telegram\Reliability\RateLimiter;
use UniversalTelegram\Tests\Integration\Support\SpyExpeditedDispatchTrigger;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * ADR-0040 §2 entry points #1 and #2: visitor start/post-message both
 * refuse with 409 `quiescence_active` outside idle, as the first statement
 * — before any schema/rate-limit/auth check.
 */
final class ConversationsControllerQuiescenceTest extends WP_UnitTestCase {

	private ConversationRepository $conversations;
	private BotProfileRepository $bots;
	private ConversationsController $controller;
	private QuiescenceGate $gate;
	private int $user_id;
	private string $nonce;

	protected function setUp(): void {
		parent::setUp();

		global $wpdb;
		$wpdb->query( 'UPDATE ' . $wpdb->prefix . Migrator::QUIESCENCE_STATE_TABLE . " SET state = 'idle', updated_at = NOW() WHERE id = 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		update_option( Settings::OPTION_NAME, array_merge( ( new Settings() )->defaults(), array( 'chat_widget_allow_anonymous' => false ) ) );

		$schema_health = new SchemaHealth();
		$vault         = new CredentialVault();

		$this->conversations = new ConversationRepository( $schema_health, new CredentialVault(), new VisitorTokenGenerator() );
		$messages            = new MessageRepository( $schema_health, $vault );
		$this->bots          = new BotProfileRepository( $schema_health, $vault );
		$destinations        = new DestinationRepository( $schema_health );
		$ai_provider         = new AIProviderRepository( $schema_health, $vault );
		$rate_limiter        = new RateLimiter( $schema_health );
		$audit_logger        = new AuditLogger( $schema_health, new Redactor() );

		$this->gate = new QuiescenceGate(
			$schema_health,
			new DeferredUpdateRepository( $schema_health, $vault ),
			new QuiescenceTransitionRepository()
		);

		$dispatcher        = new Dispatcher( $schema_health );
		$outbound_messages = new OutboundMessageRepository( $schema_health, new CredentialVault() );
		$circuit_breaker   = new CircuitBreaker( $schema_health, new RetryPolicy() );

		$outbound_handler = new ConversationOutboundHandler( $messages, $this->conversations, $outbound_messages, $dispatcher );

		$immediate_attempt = new ImmediateDeliveryAttempt(
			$this->conversations,
			$this->bots,
			$destinations,
			$outbound_messages,
			$outbound_handler,
			$messages,
			new TelegramFailureClassifier(),
			$rate_limiter,
			$circuit_breaker,
			$audit_logger,
			new RetryPolicy()
		);

		$prompt_fallback = new PromptDeliveryFallback( $immediate_attempt, new SpyExpeditedDispatchTrigger( $audit_logger ) );

		$this->controller = new ConversationsController(
			$schema_health,
			$this->conversations,
			$messages,
			new VisitorTokenGenerator(),
			new ChatProfileResolver( $this->bots, $destinations ),
			$rate_limiter,
			new TopicCreationDispatcher( $this->conversations, $dispatcher ),
			new ConversationOutboundDispatcher( $dispatcher ),
			$immediate_attempt,
			$prompt_fallback,
			new Settings(),
			$ai_provider,
			$this->gate
		);

		$this->user_id = self::factory()->user->create();
		wp_set_current_user( $this->user_id );
		$this->nonce = wp_create_nonce( 'wp_rest' );
	}

	public function test_start_returns_409_quiescence_active_while_draining(): void {
		global $wpdb;
		$table        = $wpdb->prefix . Migrator::CONVERSATIONS_TABLE;
		$count_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$this->gate->enter();

		$request = new WP_REST_Request( 'POST', '/universal-telegram/v1/conversations' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'Idempotency-Key', wp_generate_uuid4() );
		$request->set_header( 'X-Universal-Telegram-Conversation-Secret', bin2hex( random_bytes( 32 ) ) );
		$request->set_header( 'X-WP-Nonce', $this->nonce );
		$request->set_body( '' );

		$response = $this->controller->handle_start( $request );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'quiescence_active', $response->get_data()['reason'] );

		$count_after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertSame( $count_before, $count_after, 'No conversation row may be created while blocked.' );
	}

	public function test_start_proceeds_normally_while_idle(): void {
		$this->bots->create( 'Support Bot', 'token' );

		$request = new WP_REST_Request( 'POST', '/universal-telegram/v1/conversations' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'Idempotency-Key', wp_generate_uuid4() );
		$request->set_header( 'X-Universal-Telegram-Conversation-Secret', bin2hex( random_bytes( 32 ) ) );
		$request->set_header( 'X-WP-Nonce', $this->nonce );
		$request->set_body( '' );

		$response = $this->controller->handle_start( $request );

		$this->assertNotSame( 409, $response->get_status() );
	}

	public function test_post_message_returns_409_quiescence_active_while_quiescent(): void {
		global $wpdb;
		$wpdb->query( 'UPDATE ' . $wpdb->prefix . Migrator::QUIESCENCE_STATE_TABLE . " SET state = 'quiescent' WHERE id = 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$request = new WP_REST_Request( 'POST', '/universal-telegram/v1/conversations/11111111-1111-1111-1111-111111111111/messages' );
		$request->set_url_params( array( 'conversation_uuid' => '11111111-1111-1111-1111-111111111111' ) );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-WP-Nonce', $this->nonce );
		$request->set_body( wp_json_encode( array( 'text' => 'hello' ) ) );

		$response = $this->controller->handle_post_message( $request );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'quiescence_active', $response->get_data()['reason'] );
	}
}
