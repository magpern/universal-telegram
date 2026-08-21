<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Telegram\Client;

use UniversalTelegram\Telegram\Client\TelegramApiClient;
use UniversalTelegram\Telegram\Client\TelegramApiException;
use WP_Error;
use WP_UnitTestCase;

/**
 * Every response shape a real Telegram Bot API call could produce, faked via
 * WordPress' own pre_http_request filter — no live bot token is ever
 * required, committed, or reachable from CI. This file lives under
 * tests/integration rather than tests/unit (as the frozen plan originally
 * described it) because pre_http_request and wp_remote_post require a real
 * WordPress bootstrap, which tests/unit deliberately carries none of; a
 * documented, code-detail-only deviation, not an architectural one.
 */
final class TelegramApiClientTest extends WP_UnitTestCase {

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

	private function fake_network_error(): void {
		$callback = static function () {
			return new WP_Error( 'http_request_failed', 'Connection timed out' );
		};
		add_filter( 'pre_http_request', $callback, 10, 0 );
		$this->filters_to_remove[] = $callback;
	}

	public function test_get_me_success(): void {
		$this->fake_response(
			200,
			array(
				'ok'     => true,
				'result' => array(
					'id'       => 111,
					'username' => 'my_bot',
				),
			)
		);

		$client = new TelegramApiClient();
		$result = $client->get_me( 'fake-token' );

		$this->assertTrue( $result->ok() );
		$this->assertSame( 200, $result->http_status() );
		$this->assertSame( 111, $result->result()['id'] );
		$this->assertFalse( $result->is_network_error() );
	}

	public function test_400_chat_not_found(): void {
		$this->fake_response(
			400,
			array(
				'ok'          => false,
				'error_code'  => 400,
				'description' => 'Bad Request: chat not found',
			)
		);

		$client = new TelegramApiClient();
		$result = $client->send_message( 'fake-token', '123', 'hi', null, null );

		$this->assertFalse( $result->ok() );
		$this->assertSame( 400, $result->http_status() );
		$this->assertSame( 'Bad Request: chat not found', $result->description() );
	}

	public function test_401_unauthorized(): void {
		$this->fake_response(
			401,
			array(
				'ok'          => false,
				'error_code'  => 401,
				'description' => 'Unauthorized',
			)
		);

		$client = new TelegramApiClient();
		$result = $client->get_me( 'fake-token' );

		$this->assertSame( 401, $result->http_status() );
	}

	public function test_403_forbidden(): void {
		$this->fake_response(
			403,
			array(
				'ok'          => false,
				'error_code'  => 403,
				'description' => 'Forbidden: bot was kicked',
			)
		);

		$client = new TelegramApiClient();
		$result = $client->send_message( 'fake-token', '123', 'hi', null, null );

		$this->assertSame( 403, $result->http_status() );
	}

	public function test_429_with_valid_retry_after(): void {
		$this->fake_response(
			429,
			array(
				'ok'          => false,
				'error_code'  => 429,
				'description' => 'Too Many Requests',
				'parameters'  => array( 'retry_after' => 7 ),
			)
		);

		$client = new TelegramApiClient();
		$result = $client->send_message( 'fake-token', '123', 'hi', null, null );

		$this->assertSame( 429, $result->http_status() );
		$this->assertSame( 7, $result->retry_after() );
	}

	public function test_429_with_absent_retry_after_falls_back(): void {
		$this->fake_response(
			429,
			array(
				'ok'          => false,
				'error_code'  => 429,
				'description' => 'Too Many Requests',
			)
		);

		$client = new TelegramApiClient();
		$result = $client->send_message( 'fake-token', '123', 'hi', null, null );

		$this->assertSame( 429, $result->http_status() );
		$this->assertNull( $result->retry_after() );
	}

	public function test_500_server_error(): void {
		$this->fake_response(
			500,
			array(
				'ok'          => false,
				'error_code'  => 500,
				'description' => 'Internal Server Error',
			)
		);

		$client = new TelegramApiClient();
		$result = $client->get_me( 'fake-token' );

		$this->assertSame( 500, $result->http_status() );
	}

	public function test_network_transport_failure_sets_is_network_error(): void {
		$this->fake_network_error();

		$client = new TelegramApiClient();
		$result = $client->send_message( 'fake-token', '123', 'hi', null, null );

		$this->assertTrue( $result->is_network_error() );
		$this->assertNull( $result->http_status() );
	}

	public function test_a_definite_http_error_response_is_never_a_network_error(): void {
		$this->fake_response( 500, array( 'ok' => false ) );

		$result = ( new TelegramApiClient() )->send_message( 'fake-token', '123', 'hi', null, null );

		$this->assertFalse( $result->is_network_error() );
	}

	public function test_malformed_body_throws(): void {
		$callback = static function () {
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => 'not json at all {{{',
			);
		};
		add_filter( 'pre_http_request', $callback, 10, 0 );
		$this->filters_to_remove[] = $callback;

		$this->expectException( TelegramApiException::class );

		( new TelegramApiClient() )->get_me( 'fake-token' );
	}

	public function test_create_forum_topic_success(): void {
		$this->fake_response(
			200,
			array(
				'ok'     => true,
				'result' => array(
					'message_thread_id' => 42,
					'name'              => 'Conversation abc',
				),
			)
		);

		$client = new TelegramApiClient();
		$result = $client->create_forum_topic( 'fake-token', '-1001234567890', 'Conversation abc' );

		$this->assertTrue( $result->ok() );
		$this->assertSame( 42, $result->result()['message_thread_id'] );
	}

	public function test_create_forum_topic_chat_not_forum_error(): void {
		$this->fake_response(
			400,
			array(
				'ok'          => false,
				'error_code'  => 400,
				'description' => 'Bad Request: the group chat is not a forum',
			)
		);

		$client = new TelegramApiClient();
		$result = $client->create_forum_topic( 'fake-token', '-1001234567890', 'Conversation abc' );

		$this->assertFalse( $result->ok() );
		$this->assertSame( 400, $result->http_status() );
	}

	public function test_set_webhook_and_delete_webhook_success(): void {
		$this->fake_response(
			200,
			array(
				'ok'     => true,
				'result' => true,
			)
		);

		$client = new TelegramApiClient();

		$set_result = $client->set_webhook( 'fake-token', 'https://example.com/webhook', 'secret' );
		$this->assertTrue( $set_result->ok() );

		$delete_result = $client->delete_webhook( 'fake-token' );
		$this->assertTrue( $delete_result->ok() );
	}
}
