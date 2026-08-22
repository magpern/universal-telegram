<?php
/**
 * Public visitor conversation REST routes.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Conversations\Rest;

use UniversalTelegram\Conversations\ChatProfileResolver;
use UniversalTelegram\Conversations\Conversation;
use UniversalTelegram\Conversations\ConversationDisplay;
use UniversalTelegram\Conversations\ConversationOutboundDispatcher;
use UniversalTelegram\Conversations\ConversationRepository;
use UniversalTelegram\Conversations\ConversationStatus;
use UniversalTelegram\Conversations\ImmediateDeliveryAttempt;
use UniversalTelegram\Conversations\ImmediateDeliveryResult;
use UniversalTelegram\Conversations\MessageRepository;
use UniversalTelegram\Conversations\PromptDeliveryFallback;
use UniversalTelegram\Conversations\ResponseReason;
use UniversalTelegram\Conversations\TopicCreationDispatcher;
use UniversalTelegram\Conversations\VisitorTokenGenerator;
use UniversalTelegram\Core\Configuration\Settings;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Telegram\Reliability\RateLimiter;
use WP_REST_Request;
use WP_REST_Response;

/**
 * `universal-telegram/v1/conversations*` — start, resume ("mine"),
 * post-message, and short-poll. Every route requires a live WordPress
 * cookie session and a valid `X-WP-Nonce` (see authenticate_session()),
 * explicitly verified as the first statement of each handler (M06.3.1,
 * ADR-0025) — a missing/invalid session or nonce is the uniform
 * `auth_required` 401. This is additive to, never a replacement of, the
 * existing per-conversation bearer secret (docs/adr/0021, M05 plan §3–§4):
 * post-message and poll also require the bearer secret *and* an ownership
 * match (the conversation's owner_user_id === the authenticated user).
 * Every bearer/ownership failure mode (unknown/malformed conversation_uuid,
 * revoked or wrong secret, owner mismatch) produces the identical,
 * controlled 404 — no distinguishing detail. No response ever serializes
 * the bearer secret (beyond the one-time start/mine response), the stored
 * secret_hash, the WordPress numeric user id, username, or email, or any
 * raw body_ciphertext value.
 */
final class ConversationsController {

	private const ROUTE_NAMESPACE = 'universal-telegram/v1';
	private const ROUTE_START     = '/conversations';
	private const ROUTE_MINE      = '/conversations/mine';
	private const ROUTE_MESSAGES  = '/conversations/(?P<conversation_uuid>[^/]+)/messages';
	private const ROUTE_POLL      = '/conversations/(?P<conversation_uuid>[^/]+)';

	private const MAX_TEXT_CHARS = 4096;

	// Fixed, generic, never username/email/numeric id (M06.3.1, ADR-0025).
	private const FALLBACK_DISPLAY_NAME = 'Member';

	// Fixed, generic, non-PII identity for an anonymous conversation
	// (M06.3.1 addendum) — never the visitor's IP, user agent, or anything
	// else. ConversationDisplay::topic_title() already appends the
	// non-secret short reference on top of this, producing exactly
	// "Visitor · <short_ref>".
	private const ANONYMOUS_DISPLAY_NAME = 'Visitor';

	private const MINE_SCOPE    = 'conv_mine';
	private const MINE_CAPACITY = 30.0;
	private const MINE_REFILL   = 30.0 / HOUR_IN_SECONDS;

	private const RATE_LIMIT_SECRET_OPTION = 'universal_telegram_conversation_rate_limit_secret';

	// RateLimiter's shared universal_telegram_rate_limit_state table stores
	// scope_type in a VARCHAR(16) column (Migrator step 7) — every constant
	// below is deliberately kept to 16 characters or fewer. A longer value
	// would be silently truncated on write while read_state()'s own lookup
	// still queries by the full, untruncated string, so the bucket's state
	// would never be found and the limiter would never actually limit.
	private const START_SITE_SCOPE    = 'conv_start_site';
	private const START_SITE_CAPACITY = 120.0;
	private const START_SITE_REFILL   = 2.0;

	// Split into hourly and daily buckets (M06.2 corrective plan v2 §3.6,
	// ADR-0023 amendment): the prior single 5/day bucket was exhausted by a
	// widget retry bug within minutes, surfacing as an undifferentiated
	// failure. Both are additive to, never a replacement of, START_SITE_SCOPE.
	private const START_CLIENT_HOUR_SCOPE    = 'conv_start_ip_h';
	private const START_CLIENT_HOUR_CAPACITY = 10.0;
	private const START_CLIENT_HOUR_REFILL   = 10.0 / HOUR_IN_SECONDS;
	private const START_CLIENT_DAY_SCOPE     = 'conv_start_ip_d';
	private const START_CLIENT_DAY_CAPACITY  = 50.0;
	private const START_CLIENT_DAY_REFILL    = 50.0 / DAY_IN_SECONDS;

	// Consumed only on a genuine secret mismatch against a valid
	// idempotency-key replay — never on ordinary success (M06.2 corrective
	// plan v2 §3.5–§3.6).
	private const AUTH_FAIL_CLIENT_SCOPE    = 'conv_auth_fail';
	private const AUTH_FAIL_CLIENT_CAPACITY = 20.0;
	private const AUTH_FAIL_CLIENT_REFILL   = 20.0 / HOUR_IN_SECONDS;

	private const POST_SITE_SCOPE            = 'conv_post_site';
	private const POST_SITE_CAPACITY         = 300.0;
	private const POST_SITE_REFILL           = 5.0;
	private const POST_CONVERSATION_SCOPE    = 'conv_post';
	private const POST_CONVERSATION_CAPACITY = 20.0;
	private const POST_CONVERSATION_REFILL   = 20.0 / 60.0;

	private const POLL_SITE_SCOPE            = 'conv_poll_site';
	private const POLL_SITE_CAPACITY         = 1200.0;
	private const POLL_SITE_REFILL           = 20.0;
	private const POLL_CONVERSATION_SCOPE    = 'conv_poll';
	private const POLL_CONVERSATION_CAPACITY = 1.0;
	private const POLL_CONVERSATION_REFILL   = 1.0 / 2.0;

	/**
	 * Constructor.
	 *
	 * @param SchemaHealth                   $schema_health Checked before any processing.
	 * @param ConversationRepository         $conversations Conversation persistence.
	 * @param MessageRepository              $messages      Message persistence and decryption.
	 * @param VisitorTokenGenerator          $tokens        Generates the two-part visitor credential.
	 * @param ChatProfileResolver            $chat_profiles     Resolves the optional chat_profile to a bot.
	 * @param RateLimiter                    $rate_limiter      The two-tier abuse control, shared with the Telegram outbound boundary.
	 * @param TopicCreationDispatcher        $topic_creation    Idempotent Telegram forum-topic creation dispatch.
	 * @param ConversationOutboundDispatcher $outbound          Queue-before-topic visitor-to-Telegram routing dispatch.
	 * @param ImmediateDeliveryAttempt       $immediate_attempt  The bounded, claim-protected primary delivery mechanism (M06.2 corrective plan v2 §3.2, ADR-0023 amendment).
	 * @param PromptDeliveryFallback         $prompt_fallback     The host-independent bounded second-layer fallback (§3.3); owns its own ExpeditedDispatchTrigger reference for the final, demoted best-effort nudge (§3.4).
	 * @param Settings                       $settings            Reads chat_widget_allow_anonymous (M06.3.1 addendum).
	 */
	public function __construct(
		private readonly SchemaHealth $schema_health,
		private readonly ConversationRepository $conversations,
		private readonly MessageRepository $messages,
		private readonly VisitorTokenGenerator $tokens,
		private readonly ChatProfileResolver $chat_profiles,
		private readonly RateLimiter $rate_limiter,
		private readonly TopicCreationDispatcher $topic_creation,
		private readonly ConversationOutboundDispatcher $outbound,
		private readonly ImmediateDeliveryAttempt $immediate_attempt,
		private readonly PromptDeliveryFallback $prompt_fallback,
		private readonly Settings $settings
	) {}

	/**
	 * Registers the four REST routes. `mine` is registered before `poll`
	 * deliberately: `poll`'s bare `(?P<conversation_uuid>[^/]+)` pattern
	 * would otherwise also match `/conversations/mine` literally, and
	 * WP_REST_Server tries routes in registration order (M06.3.1, ADR-0025).
	 * Every route's own `permission_callback` stays `__return_true` — the
	 * cookie+nonce check is performed explicitly, in this class's own code,
	 * as the first statement of every handler (see authenticate_session()),
	 * rather than relied upon implicitly via core's cookie-authentication
	 * wiring.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			self::ROUTE_START,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_start' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			self::ROUTE_MINE,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_mine' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			self::ROUTE_MESSAGES,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_post_message' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'conversation_uuid' => array( 'required' => true ),
				),
			)
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			self::ROUTE_POLL,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_poll' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'conversation_uuid' => array( 'required' => true ),
				),
			)
		);
	}

	/**
	 * POST /conversations — start.
	 *
	 * @param WP_REST_Request $request The inbound request.
	 *
	 * @return WP_REST_Response
	 */
	public function handle_start( WP_REST_Request $request ): WP_REST_Response {
		if ( ! $this->schema_health->is_available() ) {
			return $this->respond(
				array(
					'ok'     => false,
					'reason' => ResponseReason::REQUEST_FAILED->value,
				),
				503
			);
		}

		// Auth-branch selection (M06.3.1 addendum): a logged-in visitor
		// always uses the authenticated flow, unconditionally — never the
		// anonymous one, regardless of chat_widget_allow_anonymous. A
		// logged-out visitor may start anonymously only when that setting
		// is enabled; no nonce is required or checked on the anonymous
		// path (a public, cacheable page cannot safely carry one).
		$anonymous = ! is_user_logged_in();

		if ( $anonymous ) {
			if ( ! (bool) $this->settings->get()['chat_widget_allow_anonymous'] ) {
				return $this->auth_required();
			}
		} elseif ( ! $this->authenticate_session( $request ) ) {
			return $this->auth_required();
		}

		$raw_body     = $request->get_body();
		$chat_profile = null;

		if ( '' !== trim( $raw_body ) ) {
			$decoded = json_decode( $raw_body, true );

			if ( ! is_array( $decoded ) ) {
				return $this->respond(
					array(
						'ok'     => false,
						'reason' => ResponseReason::REQUEST_FAILED->value,
					),
					400
				);
			}

			if ( isset( $decoded['chat_profile'] ) ) {
				if ( ! is_string( $decoded['chat_profile'] ) || '' === $decoded['chat_profile'] ) {
					return $this->respond(
						array(
							'ok'     => false,
							'reason' => ResponseReason::REQUEST_FAILED->value,
						),
						400
					);
				}

				$chat_profile = $decoded['chat_profile'];
			}
		}

		// Client-generated-secret start protocol (M06 plan §0, ADR-0021
		// amendment): the caller supplies both the idempotency key and the
		// bearer secret itself as headers, never in the body or a URL. A
		// malformed/missing value here is indistinguishable from any other
		// malformed start request — the same generic 400, no distinguishing
		// detail — so probing this endpoint never reveals anything.
		$idempotency_key  = (string) $request->get_header( 'Idempotency-Key' );
		$presented_secret = (string) $request->get_header( 'X-Universal-Telegram-Conversation-Secret' );

		if ( '' === $idempotency_key || ! $this->tokens->is_valid_secret_format( $presented_secret ) ) {
			return $this->respond(
				array(
					'ok'     => false,
					'reason' => ResponseReason::REQUEST_FAILED->value,
				),
				400
			);
		}

		// Replay check happens FIRST, before any new-conversation rate limit
		// is ever consumed (M06.2 corrective plan v2 §3.5, ADR-0023
		// amendment): a legitimate retry of an already-started conversation
		// must never compete with genuinely new starts for the same bucket.
		// A known key with a wrong/missing secret consumes one auth-failure
		// token and gets the identical generic 400 as any other malformed
		// request — no information about the key's existence or the correct
		// secret ever leaks (M06 plan §0 step 5).
		$existing = $this->conversations->find_by_start_idempotency_key( $idempotency_key );

		if ( null !== $existing ) {
			// The expected owner is null for an anonymous replay, or the
			// current user for an authenticated one — a single condition
			// that naturally also rejects an anonymous request replaying an
			// owned conversation's key, and vice versa.
			$expected_owner = $anonymous ? null : get_current_user_id();

			if ( null === $existing->secret_hash()
				|| ! $this->tokens->verify( $presented_secret, $existing->secret_hash() )
				|| $existing->owner_user_id() !== $expected_owner
			) {
				$this->rate_limiter->try_consume( self::AUTH_FAIL_CLIENT_SCOPE, $this->client_scope_id( 'hour' ), self::AUTH_FAIL_CLIENT_CAPACITY, self::AUTH_FAIL_CLIENT_REFILL );

				return $this->respond(
					array(
						'ok'     => false,
						'reason' => ResponseReason::REQUEST_FAILED->value,
					),
					400
				);
			}

			return $this->respond(
				array(
					'ok'                => true,
					'conversation_uuid' => $existing->conversation_uuid(),
					'secret'            => $presented_secret,
				),
				200
			);
		}

		// Only reached for a genuinely new conversation.
		if ( ! $this->rate_limiter->try_consume( self::START_SITE_SCOPE, 0, self::START_SITE_CAPACITY, self::START_SITE_REFILL ) ) {
			return $this->rate_limited();
		}

		if ( ! $this->rate_limiter->try_consume( self::START_CLIENT_HOUR_SCOPE, $this->client_scope_id( 'hour' ), self::START_CLIENT_HOUR_CAPACITY, self::START_CLIENT_HOUR_REFILL ) ) {
			return $this->rate_limited();
		}

		if ( ! $this->rate_limiter->try_consume( self::START_CLIENT_DAY_SCOPE, $this->client_scope_id( 'day' ), self::START_CLIENT_DAY_CAPACITY, self::START_CLIENT_DAY_REFILL ) ) {
			return $this->rate_limited();
		}

		$bot = null === $chat_profile ? $this->chat_profiles->default_bot() : $this->chat_profiles->find_by_profile( $chat_profile );

		if ( null === $bot ) {
			return $this->respond(
				array(
					'ok'     => false,
					'reason' => ResponseReason::REQUEST_FAILED->value,
				),
				null === $chat_profile ? 503 : 400
			);
		}

		if ( $anonymous ) {
			// The pre-M06.3.1 (M05/M06.2) anonymous model, unchanged: no
			// owner, no concurrency slot (owner_active_slot's own generated
			// CASE requires owner_user_id IS NOT NULL, so an anonymous row
			// never occupies or contends for it), a fixed non-PII identity
			// — never the visitor's IP, user agent, or anything else
			// (M06.3.1 addendum).
			$conversation = $this->conversations->create(
				$this->tokens->generate_uuid(),
				$this->tokens->hash( $presented_secret ),
				$bot->id(),
				$chat_profile,
				$idempotency_key,
				null,
				self::ANONYMOUS_DISPLAY_NAME
			);

			if ( null === $conversation ) {
				return $this->respond(
					array(
						'ok'     => false,
						'reason' => ResponseReason::REQUEST_FAILED->value,
					),
					503
				);
			}

			return $this->respond(
				array(
					'ok'                => true,
					'conversation_uuid' => $conversation->conversation_uuid(),
					'secret'            => $presented_secret,
				),
				200
			);
		}

		// Server-derived identity only (M06.3.1, ADR-0025): the visitor
		// never supplies a name. create_or_resume_owned() performs exactly
		// one insert attempt; a duplicate-key collision on the
		// owner_active_slot index (a concurrent first-Send in another tab
		// already won) resumes that existing row and rotates its secret —
		// it never retries the insert and never creates a second row.
		$result = $this->conversations->create_or_resume_owned(
			$this->tokens->generate_uuid(),
			$this->tokens->hash( $presented_secret ),
			$bot->id(),
			$chat_profile,
			$idempotency_key,
			get_current_user_id(),
			$this->resolve_display_name()
		);

		if ( null === $result ) {
			return $this->respond(
				array(
					'ok'     => false,
					'reason' => ResponseReason::REQUEST_FAILED->value,
				),
				503
			);
		}

		return $this->respond(
			array(
				'ok'                => true,
				'conversation_uuid' => $result['conversation']->conversation_uuid(),
				'secret'            => $result['resumed'] ? $result['secret'] : $presented_secret,
			),
			200
		);
	}

	/**
	 * GET /conversations/mine — cookie+nonce only (no bearer secret exists
	 * yet for a browser resuming on a new tab/session). Finds the current
	 * user's single active conversation for the resolved bot, if any, and
	 * rotates its bearer secret so it can be safely re-issued to this
	 * browser — no secret is ever stored server-side in recoverable form
	 * (M06.3.1, ADR-0025).
	 *
	 * @param WP_REST_Request $request The inbound request.
	 *
	 * @return WP_REST_Response
	 */
	public function handle_mine( WP_REST_Request $request ): WP_REST_Response {
		if ( ! $this->schema_health->is_available() ) {
			return $this->respond(
				array(
					'ok'     => false,
					'reason' => ResponseReason::REQUEST_FAILED->value,
				),
				503
			);
		}

		if ( ! $this->authenticate_session( $request ) ) {
			return $this->auth_required();
		}

		if ( ! $this->rate_limiter->try_consume( self::MINE_SCOPE, get_current_user_id(), self::MINE_CAPACITY, self::MINE_REFILL ) ) {
			return $this->rate_limited();
		}

		$chat_profile_param = $request->get_param( 'chat_profile' );
		$chat_profile       = is_string( $chat_profile_param ) && '' !== $chat_profile_param ? $chat_profile_param : null;

		$bot = null === $chat_profile ? $this->chat_profiles->default_bot() : $this->chat_profiles->find_by_profile( $chat_profile );

		if ( null === $bot ) {
			return $this->respond(
				array(
					'ok'                => true,
					'conversation_uuid' => null,
				),
				200
			);
		}

		$existing = $this->conversations->find_active_for_owner( get_current_user_id(), $bot->id() );

		if ( null === $existing ) {
			return $this->respond(
				array(
					'ok'                => true,
					'conversation_uuid' => null,
				),
				200
			);
		}

		$secret = $this->conversations->rotate_secret( $existing->id() );

		if ( null === $secret ) {
			return $this->respond(
				array(
					'ok'     => false,
					'reason' => ResponseReason::REQUEST_FAILED->value,
				),
				503
			);
		}

		return $this->respond(
			array(
				'ok'                => true,
				'conversation_uuid' => $existing->conversation_uuid(),
				'secret'            => $secret,
			),
			200
		);
	}

	/**
	 * POST /conversations/{conversation_uuid}/messages — post.
	 *
	 * @param WP_REST_Request $request The inbound request.
	 *
	 * @return WP_REST_Response
	 */
	public function handle_post_message( WP_REST_Request $request ): WP_REST_Response {
		if ( ! $this->schema_health->is_available() ) {
			return $this->respond(
				array(
					'ok'     => false,
					'reason' => ResponseReason::REQUEST_FAILED->value,
				),
				503
			);
		}

		if ( ! $this->rate_limiter->try_consume( self::POST_SITE_SCOPE, 0, self::POST_SITE_CAPACITY, self::POST_SITE_REFILL ) ) {
			return $this->rate_limited();
		}

		// Ownership is resolved from the conversation itself, not assumed
		// up front (M06.3.1 addendum): an owned conversation requires
		// cookie+nonce+owner match; an anonymous one requires only that
		// anonymous chat currently be enabled — see
		// authorize_conversation_access().
		$conversation = $this->authenticate( $request );

		if ( null === $conversation ) {
			return $this->controlled_not_found();
		}

		$authorized = $this->authorize_conversation_access( $conversation, $request );

		if ( true !== $authorized ) {
			return $authorized;
		}

		if ( ! $this->rate_limiter->try_consume( self::POST_CONVERSATION_SCOPE, $conversation->id(), self::POST_CONVERSATION_CAPACITY, self::POST_CONVERSATION_REFILL ) ) {
			return $this->rate_limited();
		}

		$content_type = (string) $request->get_header( 'Content-Type' );

		if ( ! str_starts_with( $content_type, 'application/json' ) ) {
			return $this->respond(
				array(
					'ok'     => false,
					'reason' => ResponseReason::REQUEST_FAILED->value,
				),
				400
			);
		}

		$decoded = json_decode( $request->get_body(), true );

		if ( ! is_array( $decoded ) || ! isset( $decoded['text'] ) || ! is_string( $decoded['text'] ) || '' === $decoded['text'] ) {
			return $this->respond(
				array(
					'ok'     => false,
					'reason' => ResponseReason::REQUEST_FAILED->value,
				),
				400
			);
		}

		$text = $decoded['text'];

		if ( mb_strlen( $text, 'UTF-8' ) > self::MAX_TEXT_CHARS ) {
			return $this->respond(
				array(
					'ok'     => false,
					'reason' => ResponseReason::REQUEST_FAILED->value,
				),
				400
			);
		}

		// Per-message idempotency (M06 plan §0, ADR-0021 amendment): a
		// replay of the same (conversation, idempotency key) pair returns
		// the original success response without re-running message
		// creation or any of its side effects (status transition, topic
		// creation, outbound routing) a second time.
		$idempotency_key = (string) $request->get_header( 'Idempotency-Key' );

		if ( '' !== $idempotency_key ) {
			$existing_message = $this->messages->find_by_idempotency_key( $conversation->id(), $idempotency_key );

			if ( null !== $existing_message ) {
				return $this->respond( array( 'ok' => true ), 200 );
			}
		}

		$message = $this->messages->create( $conversation->id(), 'visitor', $text, 'stored', null, '' === $idempotency_key ? null : $idempotency_key );

		if ( null === $message ) {
			return $this->respond(
				array(
					'ok'     => false,
					'reason' => ResponseReason::REQUEST_FAILED->value,
				),
				503
			);
		}

		if ( ConversationStatus::OPEN === $conversation->status() ) {
			$this->conversations->transition( $conversation->id(), ConversationStatus::OPEN, ConversationStatus::WAITING_FOR_OPERATOR );
		}

		// Safe and idempotent on every accepted visitor message, including
		// the first: only the call that wins the underlying compare-and-set
		// ever enqueues a topic-creation job (M05 plan §5). The durable
		// outbound routing job is likewise always enqueued first — the
		// bounded, claim-protected attempt below is a best-effort
		// acceleration of delivery the visitor waits on, never the only
		// path that can deliver this message (M06.2 corrective plan v2
		// §3.2, ADR-0023 amendment).
		$topic_claim = $this->topic_creation->maybe_create( $conversation );
		$this->outbound->route( $message->id(), $conversation->id() );

		$result = $this->immediate_attempt->attempt( $message, $conversation, null !== $topic_claim );

		if ( ImmediateDeliveryResult::PENDING === $result ) {
			$result = $this->prompt_fallback->run( $message, $conversation, null !== $topic_claim );
		}

		if ( ImmediateDeliveryResult::DELIVERED === $result ) {
			return $this->respond(
				array(
					'ok'       => true,
					'delivery' => 'delivered',
				),
				200
			);
		}

		return $this->respond(
			array(
				'ok'       => true,
				'delivery' => 'pending',
				'reason'   => ResponseReason::TEMPORARY_DELIVERY_PENDING->value,
			),
			200
		);
	}

	/**
	 * GET /conversations/{conversation_uuid}?since_id= — short-poll.
	 *
	 * @param WP_REST_Request $request The inbound request.
	 *
	 * @return WP_REST_Response
	 */
	public function handle_poll( WP_REST_Request $request ): WP_REST_Response {
		if ( ! $this->schema_health->is_available() ) {
			return $this->respond(
				array(
					'ok'     => false,
					'reason' => ResponseReason::REQUEST_FAILED->value,
				),
				503
			);
		}

		if ( ! $this->rate_limiter->try_consume( self::POLL_SITE_SCOPE, 0, self::POLL_SITE_CAPACITY, self::POLL_SITE_REFILL ) ) {
			return $this->rate_limited();
		}

		$conversation = $this->authenticate( $request );

		if ( null === $conversation ) {
			return $this->controlled_not_found();
		}

		$authorized = $this->authorize_conversation_access( $conversation, $request );

		if ( true !== $authorized ) {
			return $authorized;
		}

		if ( ! $this->rate_limiter->try_consume( self::POLL_CONVERSATION_SCOPE, $conversation->id(), self::POLL_CONVERSATION_CAPACITY, self::POLL_CONVERSATION_REFILL ) ) {
			return $this->rate_limited();
		}

		$since_id = max( 0, (int) $request->get_param( 'since_id' ) );

		$messages = array_map(
			function ( $message ) {
				$plaintext = $this->messages->decrypt( $message );

				return array(
					'id'             => $message->id(),
					'direction'      => $message->direction(),
					'text'           => null === $plaintext ? '[unavailable]' : $plaintext,
					'created_at'     => $message->created_at(),
					'delivery_state' => $message->delivery_state(),
				);
			},
			$this->messages->messages_since( $conversation->id(), $since_id )
		);

		return $this->respond(
			array(
				'ok'       => true,
				'status'   => $conversation->status(),
				'messages' => $messages,
			),
			200
		);
	}

	/**
	 * Resolves and authenticates a conversation from its uuid path segment
	 * and Authorization: Bearer header. There is no lookup-by-token step:
	 * the row is fetched by conversation_uuid alone, then the presented
	 * secret is verified against its stored hash (M05 plan §3).
	 *
	 * @param WP_REST_Request $request The inbound request.
	 *
	 * @return Conversation|null Null on any authentication failure.
	 */
	private function authenticate( WP_REST_Request $request ): ?Conversation {
		$conversation_uuid = (string) $request->get_param( 'conversation_uuid' );

		$conversation = $this->conversations->find_by_uuid( $conversation_uuid );

		if ( null === $conversation || null === $conversation->secret_hash() ) {
			return null;
		}

		$authorization = (string) $request->get_header( 'Authorization' );

		if ( ! str_starts_with( $authorization, 'Bearer ' ) ) {
			return null;
		}

		$presented_secret = substr( $authorization, strlen( 'Bearer ' ) );

		if ( '' === $presented_secret || ! $this->tokens->verify( $presented_secret, $conversation->secret_hash() ) ) {
			return null;
		}

		return $conversation;
	}

	/**
	 * Decides whether an already bearer-secret-verified conversation (see
	 * authenticate()) may actually be accessed, and by what rule (M06.3.1
	 * addendum). An owned conversation (owner_user_id not null) additionally
	 * requires a valid cookie session + nonce and an owner match — a
	 * missing/invalid session returns the distinct `auth_required` 401
	 * (the caller has already proven secret possession, so this reveals
	 * nothing new), while an owner mismatch returns the uniform, non-
	 * disclosing controlled_not_found() 404, exactly as before. An
	 * anonymous conversation (owner_user_id null) requires only that
	 * chat_widget_allow_anonymous currently be enabled — no nonce is ever
	 * required for it; if the setting is off (including for a conversation
	 * created while it was previously on), the identical non-disclosing 404
	 * is returned, never a distinguishing signal that the conversation
	 * exists.
	 *
	 * @param Conversation     $conversation The bearer-secret-verified conversation.
	 * @param WP_REST_Request  $request      The inbound request.
	 *
	 * @return WP_REST_Response|true True when access is authorized.
	 */
	private function authorize_conversation_access( Conversation $conversation, WP_REST_Request $request ) {
		if ( null !== $conversation->owner_user_id() ) {
			if ( ! $this->authenticate_session( $request ) ) {
				return $this->auth_required();
			}

			if ( $conversation->owner_user_id() !== get_current_user_id() ) {
				return $this->controlled_not_found();
			}

			return true;
		}

		if ( ! (bool) $this->settings->get()['chat_widget_allow_anonymous'] ) {
			return $this->controlled_not_found();
		}

		return true;
	}

	/**
	 * Explicit, self-enforced CSRF check performed as the first statement of
	 * every handler (M06.3.1, ADR-0025) — not relied upon implicitly via
	 * WordPress core's own cookie-authentication wiring, so this boundary
	 * behaves identically regardless of which other authentication handlers
	 * are registered. Requires both a live cookie session and a valid
	 * `X-WP-Nonce` header for the `wp_rest` action.
	 *
	 * @param WP_REST_Request $request The inbound request.
	 *
	 * @return bool
	 */
	private function authenticate_session( WP_REST_Request $request ): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		$nonce = (string) $request->get_header( 'X-WP-Nonce' );

		return '' !== $nonce && false !== wp_verify_nonce( $nonce, 'wp_rest' );
	}

	/**
	 * The uniform failure for a missing/invalid session or nonce — never a
	 * distinguishing detail about which of the two failed (M06.3.1, ADR-0025).
	 *
	 * @return WP_REST_Response
	 */
	private function auth_required(): WP_REST_Response {
		return $this->respond(
			array(
				'ok'     => false,
				'reason' => ResponseReason::AUTH_REQUIRED->value,
			),
			401
		);
	}

	/**
	 * The server-derived display name for the currently authenticated user
	 * (M06.3.1, ADR-0025) — the visitor never supplies one. Bounded and
	 * validated through the same ConversationDisplay helpers a client-
	 * supplied name would have used, so a pathological WordPress display
	 * name (empty, all-whitespace, absurdly long) can never produce an
	 * invalid stored value. Falls back to a fixed, generic literal — never
	 * the username, email, or numeric user id.
	 *
	 * @return string
	 */
	private function resolve_display_name(): string {
		$user = wp_get_current_user();
		$name = ConversationDisplay::bounded_utf8( trim( (string) $user->display_name ), 80 );

		return ConversationDisplay::is_valid_display_name( $name ) ? $name : self::FALLBACK_DISPLAY_NAME;
	}

	/**
	 * The identical, controlled 404 for every authentication failure mode.
	 *
	 * @return WP_REST_Response
	 */
	private function controlled_not_found(): WP_REST_Response {
		// ResponseReason::CONVERSATION_EXPIRED is attached uniformly,
		// identically, with no branching on cause, regardless of why this
		// 404 was reached — the body stays byte-for-byte identical across
		// every distinct failure cause, preserving ADR-0021's
		// non-enumeration guarantee exactly (M06.2 corrective plan v2 §3.7).
		return $this->respond(
			array(
				'ok'     => false,
				'reason' => ResponseReason::CONVERSATION_EXPIRED->value,
			),
			404
		);
	}

	/**
	 * A generic 429, no distinguishing detail about which bucket tripped.
	 *
	 * @return WP_REST_Response
	 */
	private function rate_limited(): WP_REST_Response {
		$response = $this->respond(
			array(
				'ok'     => false,
				'reason' => ResponseReason::RATE_LIMITED->value,
			),
			429
		);
		$response->header( 'Retry-After', '1' );

		return $response;
	}

	/**
	 * Builds a response with the cross-cutting headers every M05 route
	 * carries: JSON content type, no-store/no-cache, no CORS header
	 * (same-origin only) — M05 plan §4.
	 *
	 * @param array<string, mixed> $body   The response body.
	 * @param int                  $status The HTTP status code.
	 *
	 * @return WP_REST_Response
	 */
	private function respond( array $body, int $status ): WP_REST_Response {
		$response = new WP_REST_Response( $body, $status );
		$response->header( 'Content-Type', 'application/json' );
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate' );
		$response->header( 'Pragma', 'no-cache' );

		return $response;
	}

	/**
	 * Derives the per-client fairness bucket's scope_id for `start`: an
	 * HMAC of IP+hour-or-day, keyed with a per-install secret, truncated to
	 * a 31-bit integer — the same non-reversible pattern IngestController
	 * already uses (M04 plan §4.4), reused here per M05 plan §4. The
	 * granularity is generalized (M06.2 corrective plan v2 §3.6, ADR-0023
	 * amendment) so the hourly, daily, and auth-failure buckets each get
	 * their own independently self-resetting scope, no new cleanup code.
	 *
	 * @param string $granularity 'hour' or 'day' (default).
	 *
	 * @return int
	 */
	private function client_scope_id( string $granularity = 'day' ): int {
		$ip     = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$bucket = 'hour' === $granularity ? gmdate( 'Y-m-d-H' ) : gmdate( 'Y-m-d' );

		$hmac = hash_hmac( 'sha256', $ip . "\x1f" . $bucket, $this->rate_limit_secret() );

		return (int) ( hexdec( substr( $hmac, 0, 8 ) ) & 0x7FFFFFFF );
	}

	/**
	 * The per-install HMAC secret, generated once via random_bytes() and
	 * stored in a non-autoloaded option, never exposed in any UI or export.
	 *
	 * @return string
	 */
	private function rate_limit_secret(): string {
		$stored = get_option( self::RATE_LIMIT_SECRET_OPTION, '' );

		if ( is_string( $stored ) && '' !== $stored ) {
			return $stored;
		}

		$secret = bin2hex( random_bytes( 32 ) );
		add_option( self::RATE_LIMIT_SECRET_OPTION, $secret, '', false );

		return $secret;
	}
}
