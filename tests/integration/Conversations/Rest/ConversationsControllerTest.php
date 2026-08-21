<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Conversations\Rest;

use UniversalTelegram\Conversations\ChatProfileResolver;
use UniversalTelegram\Conversations\ConversationRepository;
use UniversalTelegram\Conversations\MessageRepository;
use UniversalTelegram\Conversations\Rest\ConversationsController;
use UniversalTelegram\Conversations\VisitorTokenGenerator;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;
use UniversalTelegram\Telegram\Reliability\RateLimiter;
use WP_REST_Request;
use WP_UnitTestCase;

final class ConversationsControllerTest extends WP_UnitTestCase {

	private ConversationRepository $conversations;
	private MessageRepository $messages;
	private VisitorTokenGenerator $tokens;
	private BotProfileRepository $bots;
	private ConversationsController $controller;

	protected function setUp(): void {
		parent::setUp();

		$schema_health = new SchemaHealth();
		$vault         = new CredentialVault();

		$this->conversations = new ConversationRepository( $schema_health );
		$this->messages       = new MessageRepository( $schema_health, $vault );
		$this->tokens         = new VisitorTokenGenerator();
		$this->bots           = new BotProfileRepository( $schema_health, $vault );

		$this->controller = new ConversationsController(
			$schema_health,
			$this->conversations,
			$this->messages,
			$this->tokens,
			new ChatProfileResolver( $this->bots ),
			new RateLimiter( $schema_health )
		);
	}

	private function start_request( ?string $body = null ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/universal-telegram/v1/conversations' );
		$request->set_header( 'Content-Type', 'application/json' );
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
			$this->messages_request( $started['conversation_uuid'], $started['secret'], wp_json_encode( array( 'text' => 'Hello' ) ) )
		);

		$this->assertSame( 200, $response->get_status() );
	}

	public function test_post_message_with_missing_secret_returns_uniform_404(): void {
		$started = $this->started_conversation();

		$response = $this->controller->handle_post_message(
			$this->messages_request( $started['conversation_uuid'], null, wp_json_encode( array( 'text' => 'Hello' ) ) )
		);

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( array( 'ok' => false ), $response->get_data() );
	}

	public function test_post_message_with_wrong_secret_returns_the_identical_404(): void {
		$started = $this->started_conversation();

		$response = $this->controller->handle_post_message(
			$this->messages_request( $started['conversation_uuid'], 'totally-wrong-secret', wp_json_encode( array( 'text' => 'Hello' ) ) )
		);

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( array( 'ok' => false ), $response->get_data() );
	}

	public function test_post_message_against_an_unknown_conversation_returns_the_identical_404(): void {
		$response = $this->controller->handle_post_message(
			$this->messages_request( 'nonexistent-uuid', 'any-secret', wp_json_encode( array( 'text' => 'Hello' ) ) )
		);

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( array( 'ok' => false ), $response->get_data() );
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
			$this->messages_request( $started['conversation_uuid'], $started['secret'], wp_json_encode( array( 'text' => 'first' ) ) )
		);
		$this->controller->handle_post_message(
			$this->messages_request( $started['conversation_uuid'], $started['secret'], wp_json_encode( array( 'text' => 'second' ) ) )
		);

		$response = $this->controller->handle_poll( $this->poll_request( $started['conversation_uuid'], $started['secret'] ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['ok'] );
		$this->assertCount( 2, $data['messages'] );
		$this->assertSame( 'first', $data['messages'][0]['text'] );
		$this->assertSame( 'second', $data['messages'][1]['text'] );
		$this->assertArrayNotHasKey( 'secret', $data );

		$cursor_response = $this->controller->handle_poll(
			$this->poll_request( $started['conversation_uuid'], $started['secret'], $data['messages'][0]['id'] )
		);
		$cursor_data = $cursor_response->get_data();

		$this->assertCount( 1, $cursor_data['messages'] );
		$this->assertSame( 'second', $cursor_data['messages'][0]['text'] );
	}

	public function test_poll_with_wrong_secret_returns_uniform_404(): void {
		$started = $this->started_conversation();

		$response = $this->controller->handle_poll( $this->poll_request( $started['conversation_uuid'], 'wrong-secret' ) );

		$this->assertSame( 404, $response->get_status() );
	}

	public function test_start_rate_limiting_eventually_trips_before_unbounded_row_creation(): void {
		$this->bots->create( 'Support Bot', 'token' );

		$limiter    = new RateLimiter( new SchemaHealth() );
		$controller = new ConversationsController(
			new SchemaHealth(),
			$this->conversations,
			$this->messages,
			$this->tokens,
			new ChatProfileResolver( $this->bots ),
			$limiter
		);

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
}
