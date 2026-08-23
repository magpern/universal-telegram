<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\AI\Provider\OpenAi;

use UniversalTelegram\AI\Provider\AiRequest;
use UniversalTelegram\AI\Provider\OpenAi\OpenAiAdapter;
use WP_Error;
use WP_UnitTestCase;

/**
 * Every response shape a real OpenAI Chat Completions call could produce,
 * faked via WordPress' own pre_http_request filter — no live API key is
 * ever required, committed, or reachable from CI (docs/adr/0028 decision
 * 3), mirroring TelegramApiClientTest's exact precedent.
 */
final class OpenAiAdapterTest extends WP_UnitTestCase {

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

	private function request( int $max_output_chars = 4000 ): AiRequest {
		return new AiRequest( 'gpt-4o-mini', 'You are a support draft assistant.', '<conversation>Hi</conversation>', $max_output_chars );
	}

	public function test_success_returns_the_completion_text(): void {
		$this->fake_response(
			200,
			array(
				'choices' => array(
					array( 'message' => array( 'content' => 'Here is a draft reply.' ) ),
				),
			)
		);

		$result = ( new OpenAiAdapter( 'fake-key' ) )->complete( $this->request() );

		$this->assertTrue( $result->ok() );
		$this->assertSame( 'Here is a draft reply.', $result->text() );
		$this->assertFalse( $result->truncated() );
	}

	public function test_output_over_the_bound_is_truncated_with_a_marker(): void {
		$this->fake_response(
			200,
			array(
				'choices' => array(
					array( 'message' => array( 'content' => str_repeat( 'a', 20 ) ) ),
				),
			)
		);

		$result = ( new OpenAiAdapter( 'fake-key' ) )->complete( $this->request( 10 ) );

		$this->assertTrue( $result->ok() );
		$this->assertTrue( $result->truncated() );
		$this->assertSame( str_repeat( 'a', 10 ) . ' [truncated]', $result->text() );
	}

	public function test_401_is_surfaced_as_a_failure_with_status_and_no_body(): void {
		$this->fake_response( 401, array( 'error' => array( 'message' => 'Incorrect API key provided' ) ) );

		$result = ( new OpenAiAdapter( 'bad-key' ) )->complete( $this->request() );

		$this->assertFalse( $result->ok() );
		$this->assertSame( 401, $result->http_status() );
		$this->assertNull( $result->text() );
	}

	public function test_429_is_surfaced_as_a_failure(): void {
		$this->fake_response( 429, array( 'error' => array( 'message' => 'Rate limit exceeded' ) ) );

		$result = ( new OpenAiAdapter( 'fake-key' ) )->complete( $this->request() );

		$this->assertFalse( $result->ok() );
		$this->assertSame( 429, $result->http_status() );
	}

	public function test_500_is_surfaced_as_a_failure(): void {
		$this->fake_response( 500, array( 'error' => array( 'message' => 'Internal server error' ) ) );

		$result = ( new OpenAiAdapter( 'fake-key' ) )->complete( $this->request() );

		$this->assertFalse( $result->ok() );
		$this->assertSame( 500, $result->http_status() );
	}

	public function test_network_error_is_surfaced_distinctly(): void {
		$this->fake_network_error();

		$result = ( new OpenAiAdapter( 'fake-key' ) )->complete( $this->request() );

		$this->assertFalse( $result->ok() );
		$this->assertTrue( $result->is_network_error() );
		$this->assertNull( $result->http_status() );
	}

	public function test_malformed_success_body_is_treated_as_a_failure_not_a_thrown_exception(): void {
		$this->fake_response( 200, array( 'unexpected' => 'shape' ) );

		$result = ( new OpenAiAdapter( 'fake-key' ) )->complete( $this->request() );

		$this->assertFalse( $result->ok() );
		$this->assertNull( $result->text() );
	}

	public function test_never_sends_the_api_key_anywhere_but_the_authorization_header(): void {
		$captured = null;
		$callback = static function ( $preempt, $args ) use ( &$captured ) {
			$captured = $args;
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'choices' => array( array( 'message' => array( 'content' => 'ok' ) ) ) ) ),
			);
		};
		add_filter( 'pre_http_request', $callback, 10, 2 );
		$this->filters_to_remove[] = $callback;

		( new OpenAiAdapter( 'sk-secret-value' ) )->complete( $this->request() );

		$this->assertSame( 'Bearer sk-secret-value', $captured['headers']['Authorization'] );
		$this->assertStringNotContainsString( 'sk-secret-value', $captured['body'] );
	}
}
