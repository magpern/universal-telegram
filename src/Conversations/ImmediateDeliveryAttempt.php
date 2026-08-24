<?php
/**
 * Bounded in-process delivery attempt.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Conversations;

use Throwable;
use UniversalTelegram\Audit\AuditLogger;
use UniversalTelegram\Queue\AttemptOutcome;
use UniversalTelegram\Queue\RetryPolicy;
use UniversalTelegram\Telegram\Client\TelegramApiClient;
use UniversalTelegram\Telegram\Client\TelegramFailureClassifier;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;
use UniversalTelegram\Telegram\Configuration\DestinationRepository;
use UniversalTelegram\Telegram\Outbound\OutboundMessage;
use UniversalTelegram\Telegram\Outbound\OutboundMessageRepository;
use UniversalTelegram\Telegram\Outbound\SendMessageHandler;
use UniversalTelegram\Telegram\Outbound\UnresolvedOutboundAbandoner;
use UniversalTelegram\Telegram\Reliability\CircuitBreaker;
use UniversalTelegram\Telegram\Reliability\RateLimiter;

/**
 * One bounded, wall-clock-budgeted, claim-protected attempt to deliver a
 * just-accepted visitor message to Telegram synchronously — the primary
 * interactive-latency mechanism, replacing a fire-and-forget
 * `ExpeditedDispatchTrigger` call as the thing the visitor actually waits
 * on (M06.2 corrective plan v2 §3.2, ADR-0023 amendment). Reused unmodified
 * for both the primary 4-second attempt and each bounded fallback
 * sub-attempt (§3.3) via the injectable `$budget_seconds` parameter — only
 * the caller's chosen budget differs.
 *
 * Never touches Action Scheduler's own async loopback; every durable job
 * this attempt might need (topic creation, outbound send) is already
 * enqueued by the caller before this ever runs, so a PENDING outcome never
 * loses the message — it only means the visitor's own request will not be
 * the one that delivers it promptly.
 *
 * Every Telegram API call's own timeout is capped by this attempt's
 * *remaining* wall-clock budget, never a fixed value, so a first message's
 * two-call sequence (topic creation, then send) can never exceed the
 * stated budget as a whole.
 */
final class ImmediateDeliveryAttempt {

	private const MAX_CALL_TIMEOUT_SECONDS = 3.0;
	private const OVERHEAD_MARGIN_SECONDS  = 0.2;

	/**
	 * The default primary-attempt budget (M06.2 corrective plan v2 §3.2).
	 */
	public const PRIMARY_BUDGET_SECONDS = 4.0;

	/**
	 * Constructor.
	 *
	 * @param ConversationRepository      $conversations       Conversation persistence.
	 * @param BotProfileRepository        $bots                Bot profiles, including token decryption.
	 * @param DestinationRepository       $destinations        Destinations.
	 * @param OutboundMessageRepository   $outbound_messages   Durable, encrypted outbound message storage.
	 * @param ConversationOutboundHandler $outbound_handler   Creates and durably enqueues the outbound row.
	 * @param MessageRepository           $messages            Conversation message persistence, re-read fresh each sub-attempt.
	 * @param TelegramFailureClassifier   $classifier          Classifies a failed response.
	 * @param RateLimiter                 $rate_limiter        Per-bot/per-destination token buckets.
	 * @param CircuitBreaker              $circuit_breaker     Per-bot/per-destination breakers.
	 * @param AuditLogger                 $audit_logger        Records Telegram-specific delivery events.
	 * @param RetryPolicy                 $retry_policy        Consulted only for its own max_attempts().
	 */
	public function __construct(
		private readonly ConversationRepository $conversations,
		private readonly BotProfileRepository $bots,
		private readonly DestinationRepository $destinations,
		private readonly OutboundMessageRepository $outbound_messages,
		private readonly ConversationOutboundHandler $outbound_handler,
		private readonly MessageRepository $messages,
		private readonly TelegramFailureClassifier $classifier,
		private readonly RateLimiter $rate_limiter,
		private readonly CircuitBreaker $circuit_breaker,
		private readonly AuditLogger $audit_logger,
		private readonly RetryPolicy $retry_policy
	) {}

	/**
	 * Runs one bounded attempt. Never throws — any exception (including a
	 * genuine configuration error try_once() would otherwise throw) is
	 * caught and reported as PENDING, since an in-process caller has no
	 * durable-retry bookkeeping to hand the exception to.
	 *
	 * @param ConversationMessage $message               The just-accepted visitor message.
	 * @param Conversation        $conversation           The owning conversation, freshly re-read by the caller.
	 * @param bool                $topic_claim_just_won   Whether *this* request's own `maybe_create()` call won the topic-creation claim — only the winner may call `createForumTopic` (M06.2 corrective plan v2 §3.1).
	 * @param float               $budget_seconds         The total wall-clock budget for this attempt.
	 *
	 * @return ImmediateDeliveryResult
	 */
	public function attempt( ConversationMessage $message, Conversation $conversation, bool $topic_claim_just_won, float $budget_seconds = self::PRIMARY_BUDGET_SECONDS ): ImmediateDeliveryResult {
		$deadline = microtime( true ) + $budget_seconds;

		try {
			return $this->run( $message, $conversation, $topic_claim_just_won, $deadline );
		} catch ( Throwable $exception ) {
			return ImmediateDeliveryResult::PENDING;
		}
	}

	/**
	 * The claim-aware core: topic creation if needed and won by this
	 * request, then routing and the bounded send attempt.
	 *
	 * @param ConversationMessage $message               The just-accepted visitor message.
	 * @param Conversation        $conversation           The owning conversation.
	 * @param bool                $topic_claim_just_won   Whether this request's own `maybe_create()` call won the topic-creation claim.
	 * @param float               $deadline               The absolute microtime(true) deadline for the whole attempt.
	 *
	 * @return ImmediateDeliveryResult
	 */
	private function run( ConversationMessage $message, Conversation $conversation, bool $topic_claim_just_won, float $deadline ): ImmediateDeliveryResult {
		// Re-read the message: across the fallback layer's own sequential
		// sub-attempts (§3.3), an earlier sub-attempt may already have
		// routed it — create_and_route() must never run twice for the same
		// visitor message, or two outbound rows (and two Telegram sends)
		// would result.
		$message = $this->messages->find( $message->id() ) ?? $message;

		if ( 'stored' !== $message->delivery_state() ) {
			if ( 'failed' === $message->delivery_state() || null === $message->outbound_message_uuid() ) {
				// Terminal, or nothing to resume — truthfully surfaced via
				// the poll response's own delivery_state, never retried here.
				return ImmediateDeliveryResult::PENDING;
			}

			$outbound = $this->outbound_messages->find_by_uuid( $message->outbound_message_uuid() );

			if ( null === $outbound ) {
				return ImmediateDeliveryResult::PENDING;
			}

			return $this->attempt_send( $outbound, $deadline );
		}

		if ( 'created' !== $conversation->topic_creation_state() || null === $conversation->destination_id() ) {
			if ( ! $topic_claim_just_won ) {
				// A different caller either already owns the pending claim
				// with an unexpired lease, or resolution is otherwise not
				// this request's to attempt — never call Telegram.
				return ImmediateDeliveryResult::PENDING;
			}

			$timeout = $this->call_timeout_seconds( $deadline );

			if ( null === $timeout ) {
				return ImmediateDeliveryResult::PENDING;
			}

			$topic_handler = new TopicCreationHandler(
				$this->conversations,
				$this->bots,
				new ChatProfileResolver( $this->bots, $this->destinations ),
				$this->destinations,
				new TelegramApiClient( $timeout ),
				$this->retry_policy
			);

			$topic_outcome = $topic_handler->try_once( $conversation, 1 );

			if ( AttemptOutcome::DELIVERED !== $topic_outcome ) {
				return ImmediateDeliveryResult::PENDING;
			}

			$conversation = $this->conversations->find( $conversation->id() );

			if ( null === $conversation || null === $conversation->destination_id() ) {
				return ImmediateDeliveryResult::PENDING;
			}
		}

		$outbound = $this->outbound_handler->create_and_route( $message, $conversation );

		if ( null === $outbound ) {
			// Body decryption failed; already marked delivery_state=failed —
			// truthfully surfaced to the widget via the poll response, never
			// silently retried forever.
			return ImmediateDeliveryResult::PENDING;
		}

		return $this->attempt_send( $outbound, $deadline );
	}

	/**
	 * Attempts the bounded send call itself against an already-created
	 * outbound row — shared between a fresh routing and a resumed
	 * fallback sub-attempt against a row an earlier sub-attempt already
	 * created (M06.2 corrective plan v2 §3.3).
	 *
	 * @param \UniversalTelegram\Telegram\Outbound\OutboundMessage $outbound The outbound row to attempt.
	 * @param float                                                $deadline The absolute microtime(true) deadline for the whole attempt.
	 *
	 * @return ImmediateDeliveryResult
	 */
	private function attempt_send( OutboundMessage $outbound, float $deadline ): ImmediateDeliveryResult {
		$timeout = $this->call_timeout_seconds( $deadline );

		if ( null === $timeout ) {
			return ImmediateDeliveryResult::PENDING;
		}

		$bot         = $this->bots->find( $outbound->bot_id() );
		$destination = $this->destinations->find( $outbound->destination_id() );

		if ( null === $bot || null === $destination ) {
			return ImmediateDeliveryResult::PENDING;
		}

		$send_handler = new SendMessageHandler(
			$this->outbound_messages,
			$this->bots,
			$this->destinations,
			new TelegramApiClient( $timeout ),
			$this->classifier,
			$this->rate_limiter,
			$this->circuit_breaker,
			$this->audit_logger,
			$this->retry_policy,
			new UnresolvedOutboundAbandoner( $this->outbound_messages )
		);

		$send_outcome = $send_handler->try_once( $outbound, $bot, $destination, 1 );

		return AttemptOutcome::DELIVERED === $send_outcome ? ImmediateDeliveryResult::DELIVERED : ImmediateDeliveryResult::PENDING;
	}

	/**
	 * The next Telegram API call's timeout, capped by this attempt's own
	 * remaining wall-clock budget minus a fixed overhead margin — never a
	 * fixed value (M06.2 corrective plan v2 §3.2). Null once no time
	 * remains for another call at all.
	 *
	 * @param float $deadline The absolute microtime(true) deadline for the whole attempt.
	 *
	 * @return int|null
	 */
	private function call_timeout_seconds( float $deadline ): ?int {
		$remaining = $deadline - microtime( true ) - self::OVERHEAD_MARGIN_SECONDS;

		if ( $remaining <= 0 ) {
			return null;
		}

		return max( 1, (int) floor( min( self::MAX_CALL_TIMEOUT_SECONDS, $remaining ) ) );
	}
}
