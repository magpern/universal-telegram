<?php
/**
 * Inbound webhook REST route.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Telegram\Inbound;

use UniversalTelegram\Audit\AuditLogger;
use UniversalTelegram\Conversations\ChatProfileResolver;
use UniversalTelegram\Conversations\ConversationRepository;
use UniversalTelegram\Conversations\ConversationStatus;
use UniversalTelegram\Conversations\MessageRepository;
use UniversalTelegram\Conversations\OperatorIdentityRepository;
use UniversalTelegram\Conversations\TopicLifecycleState;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Privacy\Classification;
use UniversalTelegram\SupportChatAdapter\Inbound\InboundAdapterBridge;
use UniversalTelegram\Telegram\Commands\BotCommandDispatcher;
use UniversalTelegram\Telegram\Commands\CommandParser;
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
 *
 * M05 (docs/adr/0021) narrowly extends this boundary: conversation-scoped
 * content capture (route_to_conversation()) proceeds only when dedup,
 * chat-identity, and known-topic-mapping all hold, exactly as ADR-0013's
 * own metadata-only path otherwise remains byte-for-byte unchanged.
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
	 * @param SchemaHealth               $schema_health Checked before any bot lookup or insert.
	 * @param BotProfileRepository       $bots          Resolves bot_uuid to a bot profile.
	 * @param WebhookSecretVerifier      $verifier      Proves the request is authentic.
	 * @param UpdateRepository           $updates       Metadata-only, deduplicated receipt.
	 * @param ConversationRepository     $conversations Resolves the known-topic-mapping gate (docs/adr/0021).
	 * @param MessageRepository          $messages      Encrypts and persists a captured operator reply.
	 * @param ChatProfileResolver        $chat_profiles Resolves a bot's conversation-support chat id.
	 * @param OperatorIdentityRepository $operator_identities Resolves the inbound sender's mapped WordPress operator (M07, docs/adr/0026) — the inbound Telegram operator-authorization gate.
	 * @param AuditLogger                $audit         Records a rejected-unmapped-sender attempt.
	 * @param BotCommandDispatcher       $bot_commands  Handles a recognized administrative bot command (M08, docs/adr/0027) in place of reply capture.
	 * @param int                        $max_body_bytes Request body size cap, enforced before JSON decoding.
	 * @param InboundAdapterBridge|null  $adapter_bridge Optional Support Chat adapter inbound bridge (UT Adapter M1).
	 */
	public function __construct(
		private readonly SchemaHealth $schema_health,
		private readonly BotProfileRepository $bots,
		private readonly WebhookSecretVerifier $verifier,
		private readonly UpdateRepository $updates,
		private readonly ConversationRepository $conversations,
		private readonly MessageRepository $messages,
		private readonly ChatProfileResolver $chat_profiles,
		private readonly OperatorIdentityRepository $operator_identities,
		private readonly AuditLogger $audit,
		private readonly BotCommandDispatcher $bot_commands,
		private readonly int $max_body_bytes = 1048576,
		private readonly ?InboundAdapterBridge $adapter_bridge = null
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

		$is_new_update = $this->updates->record( $bot->id(), $decoded['update_id'], $update_type, $chat_id, $message_thread_id );

		if ( $is_new_update && UpdateType::MESSAGE === $update_type ) {
			if ( $this->maybe_mark_topic_unavailable( $bot->id(), $chat_id, $message_thread_id, $decoded ) ) {
				return new WP_REST_Response( array( 'ok' => true ), 200 );
			}

			if ( null !== $this->adapter_bridge
				&& $this->adapter_bridge->try_handle( $bot, $chat_id, $message_thread_id, $decoded, $decoded['update_id'] ) ) {
				return new WP_REST_Response( array( 'ok' => true ), 200 );
			}

			$parsed_command = isset( $decoded['message'] ) && is_array( $decoded['message'] )
				? CommandParser::parse( $decoded['message'], $bot->telegram_username() )
				: null;

			if ( null !== $parsed_command ) {
				$this->bot_commands->handle( $bot, $chat_id, $message_thread_id, $parsed_command, $decoded );
			} else {
				$this->maybe_route_to_conversation( $bot->id(), $chat_id, $message_thread_id, $decoded );
			}
		}

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * Conversation-scoped content capture (M05 plan §6, docs/adr/0021): a
	 * narrow, additive extension of this boundary's otherwise-unchanged
	 * metadata-only path. Proceeds only when the update's chat_id matches
	 * this bot's own configured conversation-support chat, and
	 * message_thread_id matches an existing conversation's own created
	 * topic — the caller already confirmed genuine (bot_id, update_id)
	 * dedup before calling this. If either gate fails, this method does
	 * nothing further: no conversation row, no status transition, no
	 * visitor-visible content — byte-for-byte identical to today's
	 * metadata-only outcome.
	 *
	 * @param int                  $bot_id             The receiving bot's primary key.
	 * @param string|null          $chat_id             The update's chat id, metadata already extracted.
	 * @param int|null             $message_thread_id   The update's forum topic id, metadata already extracted.
	 * @param array<string, mixed> $decoded             The full decoded update body.
	 */
	private function maybe_route_to_conversation( int $bot_id, ?string $chat_id, ?int $message_thread_id, array $decoded ): void {
		if ( null === $chat_id || null === $message_thread_id ) {
			return;
		}

		$configured_chat_id = $this->chat_profiles->conversation_chat_id( $bot_id );

		if ( null === $configured_chat_id || $configured_chat_id !== $chat_id ) {
			return;
		}

		$conversation = $this->conversations->find_by_bot_chat_thread( $bot_id, $chat_id, $message_thread_id );

		if ( null === $conversation ) {
			return;
		}

		$sender_telegram_user_id = $this->extract_sender_id( $decoded );

		if ( null === $sender_telegram_user_id ) {
			return;
		}

		$mapped_identity = $this->operator_identities->find_by_telegram_user_id( $sender_telegram_user_id );

		if ( null === $mapped_identity ) {
			// Rejected before any decrypt, store, transition, or forward —
			// the identical fail-closed shape as every other gate in this
			// method. Only a fixed rejection code plus bot/conversation
			// identifiers are ever recorded; the raw Telegram sender id is
			// SENSITIVE and is never written into the audit context, not
			// even as a hash (M07, docs/adr/0026 decision 2).
			$this->audit->record(
				'conversation.operator_reply.rejected_unmapped_sender',
				'system',
				null,
				array(
					'bot_id'          => $bot_id,
					'conversation_id' => $conversation->id(),
				),
				array(
					'bot_id'          => Classification::INTERNAL,
					'conversation_id' => Classification::INTERNAL,
				),
				Classification::INTERNAL
			);

			return;
		}

		$text = $this->extract_text( $decoded );

		if ( null === $text || '' === $text ) {
			return;
		}

		$message = $this->messages->create( $conversation->id(), 'operator', $text, 'stored', null, null, $sender_telegram_user_id );

		if ( null === $message ) {
			return;
		}

		if ( ConversationStatus::OPEN === $conversation->status() ) {
			$this->conversations->transition( $conversation->id(), ConversationStatus::OPEN, ConversationStatus::WAITING_FOR_VISITOR );
		}
	}

	/**
	 * Marks a conversation topic unavailable on forum_topic_closed / forum_topic_deleted
	 * when the exact (bot_id, chat_id, message_thread_id) tuple matches. Returns true
	 * when the update was a topic service message (handled or ignored), so callers
	 * skip reply capture and bot commands (M07.1, docs/adr/0031).
	 *
	 * @param int                  $bot_id           Receiving bot primary key.
	 * @param string|null          $chat_id          Update chat id.
	 * @param int|null             $message_thread_id Forum topic id.
	 * @param array<string, mixed> $decoded          Full update body.
	 *
	 * @return bool
	 */
	private function maybe_mark_topic_unavailable( int $bot_id, ?string $chat_id, ?int $message_thread_id, array $decoded ): bool {
		$message = $decoded['message'] ?? null;

		if ( ! is_array( $message ) ) {
			return false;
		}

		$closed  = isset( $message['forum_topic_closed'] );
		$deleted = isset( $message['forum_topic_deleted'] );

		if ( ! $closed && ! $deleted ) {
			return false;
		}

		if ( null === $chat_id || null === $message_thread_id ) {
			return true;
		}

		$conversation = $this->conversations->find_by_bot_chat_thread( $bot_id, $chat_id, $message_thread_id );

		if ( null === $conversation ) {
			return true;
		}

		$code = $deleted ? 'telegram_topic_deleted' : 'telegram_topic_closed';
		$this->conversations->mark_topic_lifecycle( $conversation->id(), TopicLifecycleState::UNAVAILABLE, $code );

		return true;
	}

	/**
	 * Reads the plaintext message text out of a decoded 'message' update.
	 * Called only once every gate in maybe_route_to_conversation() has
	 * already passed; the text is never read, logged, or inspected before
	 * that point (M05 plan §6).
	 *
	 * @param array<string, mixed> $decoded The full decoded update body.
	 *
	 * @return string|null
	 */
	private function extract_text( array $decoded ): ?string {
		if ( ! isset( $decoded['message']['text'] ) || ! is_string( $decoded['message']['text'] ) ) {
			return null;
		}

		return $decoded['message']['text'];
	}

	/**
	 * Reads the inbound sender's own numeric Telegram user id
	 * (`message.from.id`) out of a decoded 'message' update — Telegram's
	 * own account id, present on every message update and untrustworthy
	 * only until webhook authenticity (already verified before this method
	 * is ever reached) is established. Never a Telegram username, which is
	 * self-chosen and unauthenticated (M07, docs/adr/0026 decision 2).
	 *
	 * @param array<string, mixed> $decoded The full decoded update body.
	 *
	 * @return int|null
	 */
	private function extract_sender_id( array $decoded ): ?int {
		if ( ! isset( $decoded['message']['from']['id'] ) || ! is_int( $decoded['message']['from']['id'] ) ) {
			return null;
		}

		return $decoded['message']['from']['id'];
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
