<?php
/**
 * OpenAI Chat Completions adapter.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\AI\Provider\OpenAi;

use UniversalTelegram\AI\Provider\AiProviderInterface;
use UniversalTelegram\AI\Provider\AiRequest;
use UniversalTelegram\AI\Provider\AiResult;

/**
 * The only shipped provider implementation in M09 (docs/adr/0028 decision
 * 3). Every call goes through wp_remote_post(), interceptable in tests via
 * WordPress' own pre_http_request filter — mirrors
 * Telegram\Client\TelegramApiClient's exact precedent; no live OpenAI key
 * is ever required, committed, or reachable from CI. Never throws for a
 * well-formed error response (including a network-transport failure,
 * surfaced as AiResult::is_network_error()); only a truly malformed,
 * unparseable success response throws.
 */
class OpenAiAdapter implements AiProviderInterface {

	private const API_URL = 'https://api.openai.com/v1/chat/completions';

	/**
	 * Constructor.
	 *
	 * @param string $api_key         The provider API key (decrypted just-in-time by the caller).
	 * @param int    $timeout_seconds The HTTP request timeout, in seconds.
	 */
	public function __construct(
		private readonly string $api_key,
		private readonly int $timeout_seconds = 30
	) {}

	/**
	 * {@inheritDoc}
	 */
	public function complete( AiRequest $request ): AiResult {
		$body = array(
			'model'    => $request->model(),
			'messages' => array(
				array(
					'role'    => 'system',
					'content' => $request->system_prompt(),
				),
				array(
					'role'    => 'user',
					'content' => $request->user_content(),
				),
			),
			// A generous token ceiling relative to the character bound —
			// the character truncation below is the actual enforced bound;
			// this only prevents a pathological, needlessly long raw
			// completion from the provider itself.
			'max_tokens' => (int) ceil( $request->max_output_chars() / 2 ),
		);

		$response = $this->send_request( $body );

		if ( is_wp_error( $response ) ) {
			return new AiResult( false, null, false, null, true );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( $status < 200 || $status >= 300 ) {
			// Never persist or return the raw provider error body
			// (docs/adr/0028 decision 4) — only the bounded status code
			// needed for classification.
			return new AiResult( false, null, false, $status, false );
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		$text = is_array( $decoded )
			? ( $decoded['choices'][0]['message']['content'] ?? null )
			: null;

		if ( ! is_string( $text ) || '' === trim( $text ) ) {
			return new AiResult( false, null, false, $status, false );
		}

		$truncated = strlen( $text ) > $request->max_output_chars();
		$bounded   = $truncated
			? substr( $text, 0, $request->max_output_chars() ) . ' [truncated]'
			: $text;

		return new AiResult( true, $bounded, $truncated, $status, false );
	}

	/**
	 * Performs the raw HTTP call. Overridable by tests that cannot rely on
	 * WordPress' pre_http_request filter; production code never overrides
	 * it.
	 *
	 * @param array<string, mixed> $body The request body.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	protected function send_request( array $body ) {
		return wp_remote_post(
			self::API_URL,
			array(
				'timeout' => $this->timeout_seconds,
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);
	}
}
