<?php
/**
 * Telegram Bot API HTTP client.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Telegram\Client;

/**
 * A thin, testable wrapper around getMe, sendMessage, setWebhook, and
 * deleteWebhook. Every call goes through wp_remote_post(), interceptable in
 * tests via WordPress' own pre_http_request filter — no live bot token is
 * ever required, committed, or reachable from CI. Never throws for a
 * well-formed error response (including a network-transport failure,
 * surfaced as TelegramApiResult::is_network_error()); only a truly
 * malformed, unparseable response throws TelegramApiException.
 */
class TelegramApiClient {

	private const API_BASE = 'https://api.telegram.org/bot';

	/**
	 * Constructor.
	 *
	 * @param int $timeout_seconds The HTTP request timeout, in seconds.
	 *                              Uninstaller uses a shorter bound (5s)
	 *                              for its own best-effort deleteWebhook
	 *                              call; every other caller uses the
	 *                              default.
	 */
	public function __construct( private readonly int $timeout_seconds = 10 ) {}

	/**
	 * Calls getMe. Used only as a synchronous, admin-triggered token-validity
	 * test — never a queued send, never on the frontend request path.
	 *
	 * @param string $token The bot token.
	 *
	 * @return TelegramApiResult
	 *
	 * @throws TelegramApiException If the response cannot be parsed at all.
	 */
	public function get_me( string $token ): TelegramApiResult {
		return $this->call( $token, 'getMe', array() );
	}

	/**
	 * Calls sendMessage. Used exclusively through the queue
	 * (Telegram\Outbound\SendMessageHandler), never synchronously.
	 *
	 * @param string      $token             The bot token.
	 * @param string      $chat_id            The target chat ID.
	 * @param string      $text               The message text.
	 * @param int|null    $message_thread_id  The forum topic ID, supergroup destinations only.
	 * @param string|null $parse_mode         Telegram's own parse_mode parameter.
	 *
	 * @return TelegramApiResult
	 *
	 * @throws TelegramApiException If the response cannot be parsed at all.
	 */
	public function send_message( string $token, string $chat_id, string $text, ?int $message_thread_id, ?string $parse_mode ): TelegramApiResult {
		$args = array(
			'chat_id' => $chat_id,
			'text'    => $text,
		);

		if ( null !== $message_thread_id ) {
			$args['message_thread_id'] = $message_thread_id;
		}

		if ( null !== $parse_mode ) {
			$args['parse_mode'] = $parse_mode;
		}

		return $this->call( $token, 'sendMessage', $args );
	}

	/**
	 * Calls setWebhook.
	 *
	 * @param string $token        The bot token.
	 * @param string $url          The HTTPS webhook URL.
	 * @param string $secret_token The secret to be echoed back on every delivery.
	 *
	 * @return TelegramApiResult
	 *
	 * @throws TelegramApiException If the response cannot be parsed at all.
	 */
	public function set_webhook( string $token, string $url, string $secret_token ): TelegramApiResult {
		return $this->call(
			$token,
			'setWebhook',
			array(
				'url'          => $url,
				'secret_token' => $secret_token,
			)
		);
	}

	/**
	 * Calls createForumTopic. Used only by
	 * Conversations\TopicCreationHandler, exactly once per conversation,
	 * gated on the caller's own compare-and-set guard (docs/adr/0021).
	 *
	 * @param string $token   The bot token.
	 * @param string $chat_id The supergroup chat ID the topic is created in.
	 * @param string $name    The forum topic's name.
	 *
	 * @return TelegramApiResult
	 *
	 * @throws TelegramApiException If the response cannot be parsed at all.
	 */
	public function create_forum_topic( string $token, string $chat_id, string $name ): TelegramApiResult {
		return $this->call(
			$token,
			'createForumTopic',
			array(
				'chat_id' => $chat_id,
				'name'    => $name,
			)
		);
	}

	/**
	 * Calls deleteWebhook. Used by Uninstaller, best-effort, bounded-timeout.
	 *
	 * @param string $token The bot token.
	 *
	 * @return TelegramApiResult
	 *
	 * @throws TelegramApiException If the response cannot be parsed at all.
	 */
	public function delete_webhook( string $token ): TelegramApiResult {
		return $this->call( $token, 'deleteWebhook', array() );
	}

	/**
	 * Performs one Bot API call and parses its response into a uniform
	 * TelegramApiResult.
	 *
	 * @param string               $token  The bot token.
	 * @param string               $method The Bot API method name.
	 * @param array<string, mixed> $args   The method's own parameters.
	 *
	 * @return TelegramApiResult
	 *
	 * @throws TelegramApiException If the response cannot be parsed at all.
	 */
	protected function call( string $token, string $method, array $args ): TelegramApiResult {
		$response = $this->send_request( self::API_BASE . $token . '/' . $method, $args );

		if ( is_wp_error( $response ) ) {
			return new TelegramApiResult( false, null, null, null, null, null, true );
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$body    = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );

		if ( ! is_array( $decoded ) ) {
			throw new TelegramApiException( 'Telegram API response could not be parsed.' );
		}

		$ok          = isset( $decoded['ok'] ) && true === $decoded['ok'];
		$result      = isset( $decoded['result'] ) && is_array( $decoded['result'] ) ? $decoded['result'] : null;
		$error_code  = isset( $decoded['error_code'] ) && is_int( $decoded['error_code'] ) ? $decoded['error_code'] : null;
		$description = isset( $decoded['description'] ) && is_string( $decoded['description'] ) ? $decoded['description'] : null;
		$parameters  = isset( $decoded['parameters'] ) && is_array( $decoded['parameters'] ) ? $decoded['parameters'] : null;

		return new TelegramApiResult( $ok, $status, $result, $error_code, $description, $parameters, false );
	}

	/**
	 * Performs the raw HTTP call. Overridable by tests that cannot rely on
	 * WordPress' pre_http_request filter (e.g. a pure-unit-test context);
	 * production code never overrides it.
	 *
	 * @param string               $url  The full request URL.
	 * @param array<string, mixed> $args The request body arguments.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	protected function send_request( string $url, array $args ) {
		return wp_remote_post(
			$url,
			array(
				'timeout' => $this->timeout_seconds,
				'body'    => $args,
			)
		);
	}
}
