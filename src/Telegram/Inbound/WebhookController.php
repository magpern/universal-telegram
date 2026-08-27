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
use UniversalTelegram\Migration\DeferredReplayContext;
use UniversalTelegram\Migration\QuiescenceGate;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Privacy\Classification;
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
	 * @param SchemaHealth                   $schema_health Checked before any bot lookup or insert.
	 * @param BotProfileRepository           $bots          Resolves bot_uuid to a bot profile.
	 * @param WebhookSecretVerifier          $verifier      Proves the request is authentic.
	 * @param UpdateRepository               $updates       Metadata-only, deduplicated receipt.
	 * @param ConversationRepository         $conversations Resolves the known-topic-mapping gate (docs/adr/0021).
	 * @param MessageRepository              $messages      Encrypts and persists a captured operator reply.
	 * @param ChatProfileResolver            $chat_profiles Resolves a bot's conversation-support chat id.
	 * @param OperatorIdentityRepository     $operator_identities Resolves the inbound sender's mapped WordPress operator (M07, docs/adr/0026) — the inbound Telegram operator-authorization gate.
	 * @param AuditLogger                    $audit         Records a rejected-unmapped-sender attempt.
	 * @param BotCommandDispatcher           $bot_commands  Handles a recognized administrative bot command (M08, docs/adr/0027) in place of reply capture.
	 * @param int                            $max_body_bytes Request body size cap, enforced before JSON decoding.
	 * @param InboundAdapterBridge|null      $adapter_bridge Optional Support Chat adapter inbound bridge (UT Adapter M1).
	 * @param QuiescenceGate|null            $quiescence     Legacy-chat quiescence write-blocking gate (docs/adr/0040). Null only in a not-yet-migrated install.
	 * @param ChannelBindingRepository|null  $bindings       Resolves an active binding for the cross-talk fix below (docs/adr/0042 §5). Null only in a not-yet-migrated install.
	 * @param SupportChatContractClient|null $sc_client      Dispatches `report_channel_unavailable` for the cross-talk fix below. Null only in a not-yet-migrated install.
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
		private readonly ?InboundAdapterBridge $adapter_bridge = null,
		private readonly ?QuiescenceGate $quiescence = null,
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

		list( $update_type ) = $this->extract_metadata( $decoded );

		// Legacy-chat quiescence write-blocking (docs/adr/0040 §3): checked
		// immediately after Telegram-secret authentication and before the
		// existing inbound_updates dedup insert or any command/reply
		// routing — both now folded into process_update(). Every state
		// except 'idle' buffers this arrival, encrypted, instead of
		// processing it live; a duplicate delivery of an already-buffered
		// (bot_id, update_id) is idempotent, never an error.
		if ( null !== $this->quiescence ) {
			$disposition = $this->quiescence->decide_webhook_disposition(
				$bot->id(),
				$decoded['update_id'],
				$update_type->value,
				$decoded
			);

			if ( 'buffered' === $disposition ) {
				return new WP_REST_Response( array( 'ok' => true ), 200 );
			}
		}

		$this->process_update( $bot, $decoded );

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * The full normal inbound-update processing pipeline: the existing
	 * inbound_updates dedup insert, topic-lifecycle detection, the
	 * InboundAdapterBridge refusal-first check, bot-command dispatch, and
	 * plain-text reply capture (docs/adr/0040 §3). Shared, byte-for-byte
	 * identical, by both the live webhook path (called with
	 * `$replay_context = null`, reached only when quiescence state is
	 * `idle`) and the internal replayer (`Migration\Cli\QuiescenceCommand`,
	 * called with a real `DeferredReplayContext` obtained from
	 * `QuiescenceGate::issue_replay_context()`), so there is only one
	 * implementation of this pipeline to keep in sync.
	 *
	 * Takes an explicit `BotProfile` rather than the two-parameter shape
	 * ADR-0040 §3 first described: the live path already resolves the bot
	 * from `bot_uuid` before dedup/routing can run at all, and the replayer
	 * resolves it from a deferred row's own stored `bot_id` — neither call
	 * site has a `bot_uuid` to re-resolve from inside this method, and nothing
	 * in the decoded Telegram update payload itself identifies which of this
	 * plugin's bots received it.
	 *
	 * @param BotProfile                 $bot            The receiving bot, already resolved.
	 * @param array<string, mixed>       $decoded        The full decoded update body.
	 * @param DeferredReplayContext|null $replay_context Non-null only when called by the internal replayer.
	 */
	public function process_update( BotProfile $bot, array $decoded, ?DeferredReplayContext $replay_context = null ): void {
		list( $update_type, $chat_id, $message_thread_id ) = $this->extract_metadata( $decoded );

		$is_new_update = $this->updates->record( $bot->id(), $decoded['update_id'], $update_type, $chat_id, $message_thread_id );

		if ( $is_new_update && UpdateType::MESSAGE === $update_type ) {
			if ( $this->maybe_report_active_binding_unavailable( $bot->id(), $message_thread_id, $decoded ) ) {
				return;
			}

			if ( $this->maybe_mark_topic_unavailable( $bot->id(), $chat_id, $message_thread_id, $decoded ) ) {
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
				$this->bot_commands->handle( $bot, $chat_id, $message_thread_id, $parsed_command, $decoded, $replay_context );
			} else {
				$this->maybe_route_to_conversation( $bot->id(), $chat_id, $message_thread_id, $decoded );
			}
		}
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
	 * The `maybe_mark_topic_unavailable()` live-webhook cross-talk fix
	 * (docs/adr/0042 §5): checked before that legacy lookup runs, for
	 * topic-lifecycle service messages only. If an **active** Support Chat
	 * binding exists for this update's `(bot_id, message_thread_id)`, the
	 * event is dispatched via the existing, already-idempotent
	 * `report_channel_unavailable` Contract call (the same fixed
	 * `reason_code` vocabulary legacy already emits) and is considered
	 * handled — it never reaches `maybe_mark_topic_unavailable()`'s legacy
	 * mutation. If no active binding exists, this method does nothing and
	 * existing legacy behavior is retained unchanged.
	 *
	 * Fail-closed, mirroring `InboundAdapterBridge::try_handle()`'s own
	 * "claimed but fail-closed for channel only" pattern exactly: even if
	 * the Contract call itself fails (adapter unpaired, discovery
	 * incompatible, transport failure), the event is still considered
	 * claimed and does not fall through to legacy mutation, since a topic
	 * with an active binding is no longer legacy-owned.
	 *
	 * This is a live-webhook-path fix only — distinct from, and never
	 * touching, the deferred-replay incident record
	 * (`Migration\CutoverReplayDispatcher`), which applies only to buffered
	 * rows processed during quiescence/replay.
	 *
	 * @param int                  $bot_id            Receiving bot primary key.
	 * @param int|null             $message_thread_id Forum topic id.
	 * @param array<string, mixed> $decoded           Full update body.
	 *
	 * @return bool True when this update was a topic-lifecycle service
	 *              message for an active-binding topic (handled here,
	 *              regardless of whether the Contract call itself
	 *              succeeded) — callers must skip every other branch.
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

		// Result intentionally unused: fail-closed "claimed regardless" per
		// this method's own docblock — the event never reaches legacy
		// mutation either way.
		$this->sc_client->report_channel_unavailable( $binding->binding_uuid(), $reason_code );

		return true;
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
	 * Public wrapper for `extract_metadata()`, for
	 * `Cli\QuiescenceCommand`'s own cohort-aware replay loop (docs/adr/0042
	 * §3): it needs a buffered row's `message_thread_id` to decide, per
	 * row, whether an active Support Chat binding now exists — the
	 * identical metadata this class's own live path already extracts, not
	 * a second implementation of the same parsing.
	 *
	 * @param array<string, mixed> $decoded The decoded update body.
	 *
	 * @return array{0: UpdateType, 1: string|null, 2: int|null}
	 */
	public function extract_metadata_for_cutover_replay( array $decoded ): array {
		return $this->extract_metadata( $decoded );
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
