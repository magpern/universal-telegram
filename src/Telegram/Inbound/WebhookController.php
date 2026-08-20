<?php
/**
 * Inbound webhook REST route.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Telegram\Inbound;

use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The single public-facing endpoint this milestone introduces:
 * universal-telegram/v1/webhook/{bot_uuid}, POST only, unauthenticated at
 * the WP-REST layer (Telegram cannot authenticate as a WP user) —
 * authenticity is proven entirely inside the callback, by
 * WebhookSecretVerifier (docs/adr/0013). Every one of the four
 * authenticity failure modes returns the identical generic 401, with no
 * distinguishing detail. The entire handler does only bounded, synchronous,
 * low-cost work: no Telegram API call, no queue dispatch.
 */
final class WebhookController {

	private const ROUTE_NAMESPACE = 'universal-telegram/v1';
	private const ROUTE           = '/webhook/(?P<bot_uuid>[0-9a-f-]{36})';

	private const SUPPORTED_UPDATE_KEYS = array(
		'message'             => UpdateType::MESSAGE,
		'edited_message'      => UpdateType::EDITED_MESSAGE,
		'channel_post'        => UpdateType::CHANNEL_POST,
		'edited_channel_post' => UpdateType::EDITED_CHANNEL_POST,
	);

	/**
	 * Constructor.
	 *
	 * @param SchemaHealth          $schema_health Checked before any bot lookup or insert.
	 * @param BotProfileRepository  $bots          Resolves bot_uuid to a bot profile.
	 * @param WebhookSecretVerifier $verifier      Proves the request is authentic.
	 * @param UpdateRepository      $updates       Metadata-only, deduplicated receipt.
	 * @param int                   $max_body_bytes Request body size cap, enforced before JSON decoding.
	 */
	public function __construct(
		private readonly SchemaHealth $schema_health,
		private readonly BotProfileRepository $bots,
		private readonly WebhookSecretVerifier $verifier,
		private readonly UpdateRepository $updates,
		private readonly int $max_body_bytes = 1048576
	) {}

	/**
	 * Registers the REST route.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_request' ),
				// Telegram cannot authenticate as a WP user; authenticity is
				// proven inside the callback by the secret header, not by
				// WP-REST's own auth layer — a standard pattern for public
				// webhook receivers.
				'permission_callback' => '__return_true',
				'args'                => array(
					'bot_uuid' => array(
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * The REST route callback.
	 *
	 * @param WP_REST_Request $request The inbound request.
	 *
	 * @return WP_REST_Response
	 */
	public function handle_request( WP_REST_Request $request ): WP_REST_Response {
		if ( ! $this->schema_health->is_available() ) {
			return new WP_REST_Response( array( 'ok' => false ), 503 );
		}

		$bot = $this->bots->find_by_uuid( (string) $request->get_param( 'bot_uuid' ) );

		if ( null === $bot ) {
			return $this->unauthorized();
		}

		$header_secret = $request->get_header( 'X-Telegram-Bot-Api-Secret-Token' );

		if ( ! $this->verifier->verify( $bot, null === $header_secret ? null : (string) $header_secret ) ) {
			return $this->unauthorized();
		}

		$raw_body = $request->get_body();

		if ( strlen( $raw_body ) > $this->max_body_bytes ) {
			return new WP_REST_Response( array( 'ok' => false ), 413 );
		}

		$decoded = json_decode( $raw_body, true );

		if ( ! is_array( $decoded ) ) {
			return new WP_REST_Response( array( 'ok' => false ), 400 );
		}

		if ( ! isset( $decoded['update_id'] ) || ! is_int( $decoded['update_id'] ) ) {
			return new WP_REST_Response( array( 'ok' => false ), 400 );
		}

		list( $update_type, $chat_id, $message_thread_id ) = $this->extract_metadata( $decoded );

		$this->updates->record( $bot->id(), $decoded['update_id'], $update_type, $chat_id, $message_thread_id );

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * Extracts only metadata (chat ID, thread ID, update type) from a
	 * decoded update — never any message text.
	 *
	 * @param array<string, mixed> $decoded The decoded update body.
	 *
	 * @return array{0: UpdateType, 1: string|null, 2: int|null}
	 */
	private function extract_metadata( array $decoded ): array {
		foreach ( self::SUPPORTED_UPDATE_KEYS as $key => $type ) {
			if ( ! isset( $decoded[ $key ] ) || ! is_array( $decoded[ $key ] ) ) {
				continue;
			}

			$payload           = $decoded[ $key ];
			$chat_id           = isset( $payload['chat']['id'] ) ? (string) $payload['chat']['id'] : null;
			$message_thread_id = isset( $payload['message_thread_id'] ) && is_int( $payload['message_thread_id'] )
				? $payload['message_thread_id']
				: null;

			return array( $type, $chat_id, $message_thread_id );
		}

		return array( UpdateType::UNSUPPORTED, null, null );
	}

	/**
	 * The identical generic 401 for every authenticity failure mode.
	 *
	 * @return WP_REST_Response
	 */
	private function unauthorized(): WP_REST_Response {
		return new WP_REST_Response( array( 'ok' => false ), 401 );
	}
}
