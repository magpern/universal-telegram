<?php
/**
 * Inbound webhook REST route.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Telegram\Inbound;

use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\SupportChatAdapter\ChannelBindingRepository;
use UniversalTelegram\SupportChatAdapter\Inbound\InboundAdapterBridge;
use UniversalTelegram\SupportChatAdapter\Inbound\SupportChatContractClient;
use UniversalTelegram\Telegram\Commands\BotCommandDispatcher;
use UniversalTelegram\Telegram\Commands\CommandParser;
use UniversalTelegram\Telegram\Configuration\BotProfile;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The single public-facing endpoint: universal-telegram/v1/webhook/{bot_uuid},
 * POST only, unauthenticated at the WP-REST layer (Telegram cannot
 * authenticate as a WP user) — authenticity is proven entirely inside the
 * callback by {@see WebhookSecretVerifier} (docs/adr/0013). The handler
 * does only bounded, synchronous, low-cost work: no Telegram API call, no
 * queue dispatch.
 *
 * Since ADR-0044 (transport/adapter only) the inbound pipeline routes a
 * message-bearing update to, in order: the Support Chat adapter's
 * "active binding topic went unavailable" reporter, the adapter's inbound
 * bridge, and finally administrative bot-command dispatch. There is no
 * legacy conversation capture.
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
	 * @param SchemaHealth                   $schema_health  Checked before any bot lookup or insert.
	 * @param BotProfileRepository           $bots           Resolves bot_uuid to a bot profile.
	 * @param WebhookSecretVerifier          $verifier       Proves the request is authentic.
	 * @param UpdateRepository               $updates        Metadata-only, deduplicated receipt.
	 * @param BotCommandDispatcher           $bot_commands   Handles a recognized administrative bot command (docs/adr/0027).
	 * @param int                            $max_body_bytes Request body size cap, enforced before JSON decoding.
	 * @param InboundAdapterBridge|null      $adapter_bridge Support Chat adapter inbound bridge (UT Adapter M1).
	 * @param ChannelBindingRepository|null  $bindings       Resolves an active binding for the topic-unavailable reporter below.
	 * @param SupportChatContractClient|null $sc_client      Dispatches `report_channel_unavailable`.
	 */
	public function __construct(
		private readonly SchemaHealth $schema_health,
		private readonly BotProfileRepository $bots,
		private readonly WebhookSecretVerifier $verifier,
		private readonly UpdateRepository $updates,
		private readonly BotCommandDispatcher $bot_commands,
		private readonly int $max_body_bytes = 1048576,
		private readonly ?InboundAdapterBridge $adapter_bridge = null,
		private readonly ?ChannelBindingRepository $bindings = null,
		private readonly ?SupportChatContractClient $sc_client = null
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

		$this->process_update( $bot, $decoded );

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * The inbound-update processing pipeline: the inbound_updates dedup
	 * insert, then — for a new MESSAGE update — the adapter
	 * topic-unavailable reporter, the adapter inbound bridge, and finally
	 * administrative bot-command dispatch.
	 *
	 * @param BotProfile           $bot     The receiving bot, already resolved.
	 * @param array<string, mixed> $decoded The full decoded update body.
	 */
	public function process_update( BotProfile $bot, array $decoded ): void {
		list( $update_type, $chat_id, $message_thread_id ) = $this->extract_metadata( $decoded );

		$is_new_update = $this->updates->record( $bot->id(), $decoded['update_id'], $update_type, $chat_id, $message_thread_id );

		if ( ! $is_new_update || UpdateType::MESSAGE !== $update_type ) {
			return;
		}

		if ( $this->maybe_report_active_binding_unavailable( $bot->id(), $message_thread_id, $decoded ) ) {
			return;
		}

		if ( null !== $this->adapter_bridge
			&& $this->adapter_bridge->try_handle( $bot, $chat_id, $message_thread_id, $decoded, $decoded['update_id'] ) ) {
			return;
		}

		$parsed_command = isset( $decoded['message'] ) && is_array( $decoded['message'] )
			? CommandParser::parse( $decoded['message'], $bot->telegram_username() )
			: null;

		if ( null !== $parsed_command ) {
			$this->bot_commands->handle( $bot, $chat_id, $message_thread_id, $parsed_command, $decoded );
		}
	}

	/**
	 * For a `forum_topic_closed` / `forum_topic_deleted` service message on
	 * a topic that has an **active** Support Chat binding, dispatches the
	 * idempotent `report_channel_unavailable` Contract call and returns
	 * true (handled). Fail-closed: the event is considered claimed even if
	 * the Contract call itself fails, since a topic with an active binding
	 * is adapter-owned.
	 *
	 * @param int                  $bot_id            Receiving bot primary key.
	 * @param int|null             $message_thread_id Forum topic id.
	 * @param array<string, mixed> $decoded           Full update body.
	 *
	 * @return bool
	 */
	private function maybe_report_active_binding_unavailable( int $bot_id, ?int $message_thread_id, array $decoded ): bool {
		if ( null === $this->bindings || null === $this->sc_client || null === $message_thread_id ) {
			return false;
		}

		$message = $decoded['message'] ?? null;

		if ( ! is_array( $message ) ) {
			return false;
		}

		$closed  = isset( $message['forum_topic_closed'] );
		$deleted = isset( $message['forum_topic_deleted'] );

		if ( ! $closed && ! $deleted ) {
			return false;
		}

		$binding = $this->bindings->find_by_bot_topic( $bot_id, $message_thread_id );

		if ( null === $binding || ! $binding->is_active() ) {
			return false;
		}

		$reason_code = $deleted ? 'telegram_topic_deleted' : 'telegram_topic_closed';

		// Result intentionally unused: fail-closed "claimed regardless".
		$this->sc_client->report_channel_unavailable( $binding->binding_uuid(), $reason_code );

		return true;
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
