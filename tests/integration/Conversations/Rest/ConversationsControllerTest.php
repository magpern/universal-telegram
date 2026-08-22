<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Conversations\Rest;

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
use UniversalTelegram\Audit\AuditLogger;
use UniversalTelegram\Core\Security\CredentialVault;
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

final class ConversationsControllerTest extends WP_UnitTestCase {

	private ConversationRepository $conversations;
	private MessageRepository $messages;
	private VisitorTokenGenerator $tokens;
	private BotProfileRepository $bots;
	private DestinationRepository $destinations;
	private ConversationsController $controller;
	private SpyExpeditedDispatchTrigger $expedited_dispatch;

	protected function setUp(): void {
		parent::setUp();

		$schema_health = new SchemaHealth();
		$vault         = new CredentialVault();

		$this->conversations      = new ConversationRepository( $schema_health, new CredentialVault() );
		$this->messages           = new MessageRepository( $schema_health, $vault );
		$this->tokens             = new VisitorTokenGenerator();
		$this->bots               = new BotProfileRepository( $schema_health, $vault );
		$this->destinations       = new DestinationRepository( $schema_health );
		$this->expedited_dispatch = new SpyExpeditedDispatchTrigger( new AuditLogger( $schema_health, new Redactor() ) );

		$this->controller = $this->build_controller( $schema_health, new RateLimiter( $schema_health ), $this->expedited_dispatch );
	}

	/**
	 * Builds a fully wired controller — including the immediate/fallback
	 * delivery layers (M06.2 corrective plan v2 §3.2–§3.3, ADR-0023
	 * amendment) — sharing this test's own repositories so assertions
	 * against them stay valid.
	 *
	 * @param SchemaHealth                $schema_health      Checked before any processing.
	 * @param RateLimiter                 $rate_limiter       The rate limiter this controller and its callers share.
	 * @param SpyExpeditedDispatchTrigger $expedited_dispatch The final-fallback nudge spy.
	 *
	 * @return ConversationsController
	 */
	private function build_controller( SchemaHealth $schema_health, RateLimiter $rate_limiter, SpyExpeditedDispatchTrigger $expedited_dispatch ): ConversationsController {
		$dispatcher        = new Dispatcher( $schema_health );
		$outbound_messages = new OutboundMessageRepository( $schema_health, new CredentialVault() );
		$circuit_breaker   = new CircuitBreaker( $schema_health, new RetryPolicy() );
		$audit_logger      = new AuditLogger( $schema_health, new Redactor() );

		$outbound_handler = new ConversationOutboundHandler(
			$this->messages,
			$this->conversations,
			$outbound_messages,
			$dispatcher
		);

		$immediate_attempt = new ImmediateDeliveryAttempt(
			$this->conversations,
			$this->bots,
			$this->destinations,
			$outbound_messages,
			$outbound_handler,
			$this->messages,
			new TelegramFailureClassifier(),
			$rate_limiter,
			$circuit_breaker,
			$audit_logger,
			new RetryPolicy()
		);

		$prompt_fallback = new PromptDeliveryFallback( $immediate_attempt, $expedited_dispatch );

		return new ConversationsController(
			$schema_health,
			$this->conversations,
			$this->messages,
			$this->tokens,
			new ChatProfileResolver( $this->bots, $this->destinations ),
			$rate_limiter,
			new TopicCreationDispatcher( $this->conversations, $dispatcher ),
			new ConversationOutboundDispatcher( $dispatcher ),
			$immediate_attempt,
			$prompt_fallback
		);
	}

	private function start_request( ?string $body = null, ?string $idempotency_key = null, ?string $secret = null ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/universal-telegram/v1/conversations' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'Idempotency-Key', $idempotency_key ?? wp_generate_uuid4() );
		$request->set_header( 'X-Universal-Telegram-Conversation-Secret', $secret ?? bin2hex( random_bytes( 32 ) ) );
		$request->set_body( $body ?? '' );

		return $request;
	}

	private function messages_request( string $uuid, ?string $secret, string $body ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/universal-telegram/v1/conversations/' . $uuid . '/messages' );
		$request->set_url_params( array( 'conversation_uuid' => $uuid ) );
		$request->set_header( 'Content-Type', 'application/json' );
		if ( null !== $secret ) {
			$request->set_header( 'Authorization', 'Bearer ' . $secret );
		}
		$request->set_body( $body );

		return $request;
	}

	private function poll_request( string $uuid, ?string $secret, int $since_id = 0 ): WP_REST_Request {
		$request = new WP_REST_Request( 'GET', '/universal-telegram/v1/conversations/' . $uuid );
		$request->set_url_params( array( 'conversation_uuid' => $uuid ) );
		$request->set_param( 'since_id', $since_id );
		if ( null !== $secret ) {
			$request->set_header( 'Authorization', 'Bearer ' . $secret );
		}

		return $request;
	}

	public function test_start_without_any_configured_bot_returns_503(): void {
		$response = $this->controller->handle_start( $this->start_request() );

		$this->assertSame( 503, $response->get_status() );
	}

	public function test_start_succeeds_and_returns_the_secret_exactly_once(): void {
		$this->bots->create( 'Support Bot', 'token' );

		$response = $this->controller->handle_start( $this->start_request() );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['ok'] );
		$this->assertNotEmpty( $data['conversation_uuid'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $data['secret'] );
		$this->assertArrayNotHasKey( 'secret_hash', $data );
		$this->assertTrue( $data['display_name_required'] );

		$headers = $response->get_headers();
		$this->assertSame( 'no-store, no-cache, must-revalidate', $headers['Cache-Control'] );
	}

	public function test_start_with_an_unknown_chat_profile_returns_400(): void {
		$this->bots->create( 'Support Bot', 'token' );

		$response = $this->controller->handle_start( $this->start_request( wp_json_encode( array( 'chat_profile' => 'nonexistent' ) ) ) );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_start_with_a_matching_chat_profile_succeeds(): void {
		$this->bots->create( 'Support Bot', 'token' );

		$response = $this->controller->handle_start( $this->start_request( wp_json_encode( array( 'chat_profile' => 'Support Bot' ) ) ) );

		$this->assertSame( 200, $response->get_status() );
	}

	public function test_start_with_malformed_json_body_returns_400(): void {
		$this->bots->create( 'Support Bot', 'token' );

		$response = $this->controller->handle_start( $this->start_request( '{not-json' ) );

		$this->assertSame( 400, $response->get_status() );
	}

	private function started_conversation(): array {
		$this->bots->create( 'Support Bot', 'token' );
		$response = $this->controller->handle_start( $this->start_request() );

		return $response->get_data();
	}

	public function test_post_message_with_valid_secret_succeeds(): void {
		$started = $this->started_conversation();

		$response = $this->controller->handle_post_message(
			$this->messages_request(
				$started['conversation_uuid'],
				$started['secret'],
				wp_json_encode(
					array(
						'text'         => 'Hello',
						'display_name' => 'Alice',
					)
				)
			)
		);

		$this->assertSame( 200, $response->get_status() );
	}

	public function test_post_message_with_missing_secret_returns_uniform_404(): void {
		$started = $this->started_conversation();

		$response = $this->controller->handle_post_message(
			$this->messages_request( $started['conversation_uuid'], null, wp_json_encode( array( 'text' => 'Hello' ) ) )
		);

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame(
			array(
				'ok'     => false,
				'reason' => 'conversation_expired',
			),
			$response->get_data()
		);
	}

	public function test_post_message_with_wrong_secret_returns_the_identical_404(): void {
		$started = $this->started_conversation();

		$response = $this->controller->handle_post_message(
			$this->messages_request( $started['conversation_uuid'], 'totally-wrong-secret', wp_json_encode( array( 'text' => 'Hello' ) ) )
		);

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame(
			array(
				'ok'     => false,
				'reason' => 'conversation_expired',
			),
			$response->get_data()
		);
	}

	public function test_post_message_against_an_unknown_conversation_returns_the_identical_404(): void {
		$response = $this->controller->handle_post_message(
			$this->messages_request( 'nonexistent-uuid', 'any-secret', wp_json_encode( array( 'text' => 'Hello' ) ) )
		);

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame(
			array(
				'ok'     => false,
				'reason' => 'conversation_expired',
			),
			$response->get_data()
		);
	}

	public function test_post_message_against_a_revoked_secret_returns_the_identical_404(): void {
		$started = $this->started_conversation();
		$found   = $this->conversations->find_by_uuid( $started['conversation_uuid'] );
		$this->conversations->revoke_secret( $found->id() );

		$response = $this->controller->handle_post_message(
			$this->messages_request( $started['conversation_uuid'], $started['secret'], wp_json_encode( array( 'text' => 'Hello' ) ) )
		);

		$this->assertSame( 404, $response->get_status() );
	}

	public function test_post_message_rejects_oversized_text_before_any_write(): void {
		$started = $this->started_conversation();
		$found   = $this->conversations->find_by_uuid( $started['conversation_uuid'] );

		$oversized = str_repeat( 'a', 4097 );

		$response = $this->controller->handle_post_message(
			$this->messages_request( $started['conversation_uuid'], $started['secret'], wp_json_encode( array( 'text' => $oversized ) ) )
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( array(), $this->messages->messages_since( $found->id(), 0 ) );
	}

	public function test_post_message_rejects_malformed_json_body(): void {
		$started = $this->started_conversation();

		$response = $this->controller->handle_post_message(
			$this->messages_request( $started['conversation_uuid'], $started['secret'], '{not-json' )
		);

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_post_message_rejects_a_non_json_content_type(): void {
		$started = $this->started_conversation();

		$request = $this->messages_request( $started['conversation_uuid'], $started['secret'], wp_json_encode( array( 'text' => 'Hello' ) ) );
		$request->set_header( 'Content-Type', 'text/plain' );

		$response = $this->controller->handle_post_message( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_poll_returns_decrypted_messages_ascending_since_the_cursor(): void {
		$started = $this->started_conversation();

		$this->controller->handle_post_message(
			$this->messages_request(
				$started['conversation_uuid'],
				$started['secret'],
				wp_json_encode(
					array(
						'text'         => 'first',
						'display_name' => 'Alice',
					)
				)
			)
		);
		$this->controller->handle_post_message(
			$this->messages_request( $started['conversation_uuid'], $started['secret'], wp_json_encode( array( 'text' => 'second' ) ) )
		);

		// A single poll here, deliberately: the per-conversation minimum-
		// poll-interval limiter (exercised on its own below) would otherwise
		// trip a second immediate poll in the same request. Cursor-advance
		// semantics themselves are covered at the repository layer
		// (MessageRepositoryTest::test_messages_since_returns_only_messages_after_the_cursor_ascending).
		$response = $this->controller->handle_poll( $this->poll_request( $started['conversation_uuid'], $started['secret'] ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['ok'] );
		$this->assertCount( 2, $data['messages'] );
		$this->assertSame( 'first', $data['messages'][0]['text'] );
		$this->assertSame( 'second', $data['messages'][1]['text'] );
		$this->assertArrayNotHasKey( 'secret', $data );
	}

	public function test_poll_with_wrong_secret_returns_uniform_404(): void {
		$started = $this->started_conversation();

		$response = $this->controller->handle_poll( $this->poll_request( $started['conversation_uuid'], 'wrong-secret' ) );

		$this->assertSame( 404, $response->get_status() );
	}

	public function test_start_rate_limiting_eventually_trips_before_unbounded_row_creation(): void {
		$this->bots->create( 'Support Bot', 'token' );

		$schema_health = new SchemaHealth();
		$limiter       = new RateLimiter( $schema_health );
		$controller    = $this->build_controller( $schema_health, $limiter, new SpyExpeditedDispatchTrigger( new AuditLogger( $schema_health, new Redactor() ) ) );

		$last_status = 200;
		for ( $i = 0; $i < 200; $i++ ) {
			$response    = $controller->handle_start( $this->start_request() );
			$last_status = $response->get_status();
			if ( 429 === $last_status ) {
				break;
			}
		}

		$this->assertSame( 429, $last_status );
	}

	public function test_poll_per_conversation_minimum_interval_trips_on_rapid_polling(): void {
		$started = $this->started_conversation();

		$first  = $this->controller->handle_poll( $this->poll_request( $started['conversation_uuid'], $started['secret'] ) );
		$second = $this->controller->handle_poll( $this->poll_request( $started['conversation_uuid'], $started['secret'] ) );

		$this->assertSame( 200, $first->get_status() );
		$this->assertSame( 429, $second->get_status() );
	}

	public function test_start_missing_idempotency_key_returns_400(): void {
		$this->bots->create( 'Support Bot', 'token' );

		$request = $this->start_request();
		$request->remove_header( 'Idempotency-Key' );

		$response = $this->controller->handle_start( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_start_malformed_secret_format_returns_400(): void {
		$this->bots->create( 'Support Bot', 'token' );

		$response = $this->controller->handle_start( $this->start_request( null, null, 'not-64-hex-chars' ) );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_start_replay_with_same_key_and_secret_returns_the_same_conversation_without_a_new_row(): void {
		$this->bots->create( 'Support Bot', 'token' );

		$key    = wp_generate_uuid4();
		$secret = bin2hex( random_bytes( 32 ) );

		$first  = $this->controller->handle_start( $this->start_request( null, $key, $secret ) );
		$second = $this->controller->handle_start( $this->start_request( null, $key, $secret ) );

		$first_data  = $first->get_data();
		$second_data = $second->get_data();

		$this->assertSame( 200, $first->get_status() );
		$this->assertSame( 200, $second->get_status() );
		$this->assertSame( $first_data['conversation_uuid'], $second_data['conversation_uuid'] );
		$this->assertSame( $secret, $second_data['secret'] );

		$found = $this->conversations->find_by_uuid( $first_data['conversation_uuid'] );
		$this->assertNotNull( $found );

		// No second row: the conversation found by the public uuid is the
		// same primary-key row the replay resolved via the idempotency key.
		$by_key = $this->conversations->find_by_start_idempotency_key( $key );
		$this->assertSame( $found->id(), $by_key->id() );
	}

	public function test_start_replay_with_wrong_secret_returns_the_same_generic_400_and_leaks_nothing(): void {
		$this->bots->create( 'Support Bot', 'token' );

		$key = wp_generate_uuid4();

		$first  = $this->controller->handle_start( $this->start_request( null, $key, bin2hex( random_bytes( 32 ) ) ) );
		$second = $this->controller->handle_start( $this->start_request( null, $key, bin2hex( random_bytes( 32 ) ) ) );

		$this->assertSame( 200, $first->get_status() );
		$this->assertSame( 400, $second->get_status() );
		$this->assertSame(
			array(
				'ok'     => false,
				'reason' => 'request_failed',
			),
			$second->get_data()
		);
	}

	public function test_post_message_replay_with_same_key_returns_original_response_without_a_duplicate_row(): void {
		$started = $this->started_conversation();
		$found   = $this->conversations->find_by_uuid( $started['conversation_uuid'] );

		$key = wp_generate_uuid4();

		$request = $this->messages_request(
			$started['conversation_uuid'],
			$started['secret'],
			wp_json_encode(
				array(
					'text'         => 'Hello',
					'display_name' => 'Alice',
				)
			)
		);
		$request->set_header( 'Idempotency-Key', $key );
		$first = $this->controller->handle_post_message( $request );

		$replay = $this->messages_request( $started['conversation_uuid'], $started['secret'], wp_json_encode( array( 'text' => 'Hello' ) ) );
		$replay->set_header( 'Idempotency-Key', $key );
		$second = $this->controller->handle_post_message( $replay );

		$this->assertSame( 200, $first->get_status() );
		$this->assertSame( array( 'ok' => true ), $second->get_data() );
		$this->assertCount( 1, $this->messages->messages_since( $found->id(), 0 ) );
	}

	public function test_expedited_dispatch_is_triggered_exactly_once_for_a_newly_accepted_message(): void {
		$started = $this->started_conversation();

		$this->controller->handle_post_message(
			$this->messages_request(
				$started['conversation_uuid'],
				$started['secret'],
				wp_json_encode(
					array(
						'text'         => 'Hello',
						'display_name' => 'Alice',
					)
				)
			)
		);

		$this->assertSame( 1, $this->expedited_dispatch->calls );
	}

	public function test_expedited_dispatch_is_not_triggered_on_an_idempotent_replay(): void {
		$started = $this->started_conversation();
		$key     = wp_generate_uuid4();

		$request = $this->messages_request(
			$started['conversation_uuid'],
			$started['secret'],
			wp_json_encode(
				array(
					'text'         => 'Hello',
					'display_name' => 'Alice',
				)
			)
		);
		$request->set_header( 'Idempotency-Key', $key );
		$this->controller->handle_post_message( $request );

		$replay = $this->messages_request( $started['conversation_uuid'], $started['secret'], wp_json_encode( array( 'text' => 'Hello' ) ) );
		$replay->set_header( 'Idempotency-Key', $key );
		$this->controller->handle_post_message( $replay );

		$this->assertSame( 1, $this->expedited_dispatch->calls );
	}

	public function test_expedited_dispatch_is_not_triggered_on_wrong_secret_or_unknown_conversation(): void {
		$started = $this->started_conversation();

		$this->controller->handle_post_message(
			$this->messages_request( $started['conversation_uuid'], 'totally-wrong-secret', wp_json_encode( array( 'text' => 'Hello' ) ) )
		);
		$this->controller->handle_post_message(
			$this->messages_request( 'nonexistent-uuid', 'any-secret', wp_json_encode( array( 'text' => 'Hello' ) ) )
		);

		$this->assertSame( 0, $this->expedited_dispatch->calls );
	}

	public function test_expedited_dispatch_is_not_triggered_on_oversized_text_rejection(): void {
		$started   = $this->started_conversation();
		$oversized = str_repeat( 'a', 4097 );

		$this->controller->handle_post_message(
			$this->messages_request( $started['conversation_uuid'], $started['secret'], wp_json_encode( array( 'text' => $oversized ) ) )
		);

		$this->assertSame( 0, $this->expedited_dispatch->calls );
	}

	public function test_post_message_without_a_display_name_is_rejected_and_nothing_is_persisted_or_routed(): void {
		$started = $this->started_conversation();
		$found   = $this->conversations->find_by_uuid( $started['conversation_uuid'] );

		$response = $this->controller->handle_post_message(
			$this->messages_request( $started['conversation_uuid'], $started['secret'], wp_json_encode( array( 'text' => 'Hello' ) ) )
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( array(), $this->messages->messages_since( $found->id(), 0 ) );
		$this->assertSame( 0, $this->expedited_dispatch->calls );
		$this->assertTrue( $this->conversations->find( $found->id() )->display_name_required() );
	}

	public function test_post_message_with_an_empty_display_name_is_rejected(): void {
		$started = $this->started_conversation();

		$response = $this->controller->handle_post_message(
			$this->messages_request(
				$started['conversation_uuid'],
				$started['secret'],
				wp_json_encode(
					array(
						'text'         => 'Hello',
						'display_name' => '   ',
					)
				)
			)
		);

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_post_message_with_an_oversized_display_name_is_rejected(): void {
		$started = $this->started_conversation();

		$response = $this->controller->handle_post_message(
			$this->messages_request(
				$started['conversation_uuid'],
				$started['secret'],
				wp_json_encode(
					array(
						'text'         => 'Hello',
						'display_name' => str_repeat( 'a', 81 ),
					)
				)
			)
		);

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_post_message_with_a_valid_display_name_persists_it_and_flips_display_name_required(): void {
		$started = $this->started_conversation();
		$found   = $this->conversations->find_by_uuid( $started['conversation_uuid'] );

		$response = $this->controller->handle_post_message(
			$this->messages_request(
				$started['conversation_uuid'],
				$started['secret'],
				wp_json_encode(
					array(
						'text'         => 'Hello',
						'display_name' => '  Alice  ',
					)
				)
			)
		);

		$this->assertSame( 200, $response->get_status() );

		$stored = $this->conversations->find( $found->id() );
		$this->assertFalse( $stored->display_name_required() );
		$this->assertSame( 'Alice', $this->conversations->decrypt_display_name( $stored ) );
	}

	public function test_poll_reports_display_name_required_until_a_name_is_stored(): void {
		$started = $this->started_conversation();

		$before = $this->controller->handle_poll( $this->poll_request( $started['conversation_uuid'], $started['secret'] ) );
		$this->assertTrue( $before->get_data()['display_name_required'] );

		$this->controller->handle_post_message(
			$this->messages_request(
				$started['conversation_uuid'],
				$started['secret'],
				wp_json_encode(
					array(
						'text'         => 'Hello',
						'display_name' => 'Alice',
					)
				)
			)
		);

		// A fresh controller/rate-limiter for the second poll: the shared
		// controller's per-conversation poll limiter (capacity 1) was
		// already consumed by $before above, and this assertion is about
		// display_name_required, not rate limiting (covered separately by
		// test_poll_per_conversation_minimum_interval_trips_on_rapid_polling).
		$schema_health     = new SchemaHealth();
		$second_controller = $this->build_controller( $schema_health, new RateLimiter( $schema_health ), new SpyExpeditedDispatchTrigger( new AuditLogger( $schema_health, new Redactor() ) ) );

		$after = $second_controller->handle_poll( $this->poll_request( $started['conversation_uuid'], $started['secret'] ) );
		$this->assertFalse( $after->get_data()['display_name_required'] );
	}

	public function test_a_later_message_silently_ignores_a_display_name_field_and_does_not_overwrite(): void {
		$started = $this->started_conversation();
		$found   = $this->conversations->find_by_uuid( $started['conversation_uuid'] );

		$this->controller->handle_post_message(
			$this->messages_request(
				$started['conversation_uuid'],
				$started['secret'],
				wp_json_encode(
					array(
						'text'         => 'first',
						'display_name' => 'Alice',
					)
				)
			)
		);

		$response = $this->controller->handle_post_message(
			$this->messages_request(
				$started['conversation_uuid'],
				$started['secret'],
				wp_json_encode(
					array(
						'text'         => 'second',
						'display_name' => 'Bob',
					)
				)
			)
		);

		$this->assertSame( 200, $response->get_status() );

		$stored = $this->conversations->find( $found->id() );
		$this->assertSame( 'Alice', $this->conversations->decrypt_display_name( $stored ) );
	}

	public function test_post_message_replay_does_not_re_store_or_duplicate_the_display_name(): void {
		$started = $this->started_conversation();
		$found   = $this->conversations->find_by_uuid( $started['conversation_uuid'] );
		$key     = wp_generate_uuid4();

		$request = $this->messages_request(
			$started['conversation_uuid'],
			$started['secret'],
			wp_json_encode(
				array(
					'text'         => 'Hello',
					'display_name' => 'Alice',
				)
			)
		);
		$request->set_header( 'Idempotency-Key', $key );
		$this->controller->handle_post_message( $request );

		$replay = $this->messages_request(
			$started['conversation_uuid'],
			$started['secret'],
			wp_json_encode(
				array(
					'text'         => 'Hello',
					'display_name' => 'Alice',
				)
			)
		);
		$replay->set_header( 'Idempotency-Key', $key );
		$second = $this->controller->handle_post_message( $replay );

		$this->assertSame( array( 'ok' => true ), $second->get_data() );

		$stored = $this->conversations->find( $found->id() );
		$this->assertSame( 'Alice', $this->conversations->decrypt_display_name( $stored ) );
		$this->assertCount( 1, $this->messages->messages_since( $found->id(), 0 ) );
	}
}
