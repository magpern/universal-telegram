<?php
/**
 * Outbound message send handler.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Telegram\Outbound;

use RuntimeException;
use UniversalTelegram\Audit\AuditLogger;
use UniversalTelegram\Core\Security\CredentialState;
use UniversalTelegram\Privacy\Classification;
use UniversalTelegram\Queue\AttemptOutcome;
use UniversalTelegram\Queue\RetryPolicy;
use UniversalTelegram\Queue\WorkerRunner;
use UniversalTelegram\Telegram\Client\FailureClassification;
use UniversalTelegram\Telegram\Client\TelegramApiClient;
use UniversalTelegram\Telegram\Client\TelegramFailureClassifier;
use UniversalTelegram\Telegram\Client\TelegramApiResult;
use UniversalTelegram\Telegram\Configuration\BotProfile;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;
use UniversalTelegram\Telegram\Configuration\BotStatus;
use UniversalTelegram\Telegram\Configuration\Destination;
use UniversalTelegram\Telegram\Configuration\DestinationKind;
use UniversalTelegram\Telegram\Configuration\DestinationRepository;
use UniversalTelegram\Telegram\Reliability\CircuitBreaker;
use UniversalTelegram\Telegram\Reliability\CircuitOpenException;
use UniversalTelegram\Telegram\Reliability\RateLimiter;

/**
 * The queue's registered handler for MessageDispatcher::JOB_TYPE. Re-reads
 * and decrypts the message row at execution time — the JobEnvelope payload
 * never carries text or a token (docs/adr/0012). Implements the full
 * Telegram-specific reliability decision tree on top of the generic queue
 * (docs/adr/0014): rate-limit and circuit-open deferrals never throw and
 * never consume Queue\RetryPolicy's attempt budget; TERMINAL and
 * TOKEN_INVALID failures dead-letter immediately without throwing;
 * RETRYABLE failures rethrow so WorkerRunner's own generic sequence runs,
 * except on the final permitted attempt, where this handler also
 * dead-letters before rethrowing.
 */
class SendMessageHandler {

	private const BOT_CIRCUIT_THRESHOLD         = 5;
	private const DESTINATION_CIRCUIT_THRESHOLD = 3;
	private const CIRCUIT_WINDOW_SECONDS        = 600;

	private const BOT_RATE_CAPACITY            = 20.0;
	private const BOT_RATE_REFILL_PER_SECOND   = 20.0;
	private const DESTINATION_RATE_CAPACITY    = 1.0;
	private const DESTINATION_RATE_REFILL      = 1.0;
	private const GROUP_RATE_CAPACITY          = 20.0;
	private const GROUP_RATE_REFILL_PER_SECOND = 20.0 / 60.0;

	private const LOCAL_RATE_LIMIT_DEFER_SECONDS = 2;

	/**
	 * Constructor.
	 *
	 * @param OutboundMessageRepository $messages                     Durable, encrypted message storage.
	 * @param BotProfileRepository      $bots                         Bot profiles, including token decryption.
	 * @param DestinationRepository     $destinations                 Destinations.
	 * @param TelegramApiClient         $client                       The Telegram Bot API client.
	 * @param TelegramFailureClassifier $classifier                   Classifies a failed response.
	 * @param RateLimiter               $rate_limiter                 Per-bot/per-destination token buckets.
	 * @param CircuitBreaker            $circuit_breaker              Per-bot/per-destination breakers.
	 * @param AuditLogger               $audit_logger                 Records Telegram-specific delivery events.
	 * @param RetryPolicy               $retry_policy                 Consulted only for its own max_attempts().
	 * @param int                       $rate_limit_fallback_wait_seconds Used only when a 429's retry_after is absent/invalid.
	 * @param int                       $max_pending_seconds           The unbounded-deferral safety ceiling.
	 */
	public function __construct(
		private readonly OutboundMessageRepository $messages,
		private readonly BotProfileRepository $bots,
		private readonly DestinationRepository $destinations,
		private readonly TelegramApiClient $client,
		private readonly TelegramFailureClassifier $classifier,
		private readonly RateLimiter $rate_limiter,
		private readonly CircuitBreaker $circuit_breaker,
		private readonly AuditLogger $audit_logger,
		private readonly RetryPolicy $retry_policy,
		private readonly int $rate_limit_fallback_wait_seconds = 30,
		private readonly int $max_pending_seconds = 86400
	) {}

	/**
	 * The Action Scheduler job handler. A thin wrapper around try_once():
	 * resolves the message/bot/destination, applies the pending-ceiling
	 * safety valve, then translates the shared AttemptOutcome into this
	 * queue's own existing scheduling behavior (M06.2 corrective plan v2
	 * §3.2, ADR-0023 amendment).
	 *
	 * @param array{job_id: string, job_type: string, attempt: int, payload: array<string, mixed>} $job The job.
	 *
	 * @throws RuntimeException On a RETRYABLE failure, so WorkerRunner's
	 *                           own generic retry sequence runs.
	 */
	public function handle_job( array $job ): void {
		$message_uuid   = (string) $job['payload']['message_uuid'];
		$bot_id         = (int) $job['payload']['bot_id'];
		$destination_id = (int) $job['payload']['destination_id'];

		$message = $this->messages->find_by_uuid( $message_uuid );

		if ( null === $message ) {
			throw new RuntimeException( 'telegram_outbound_message_not_found' );
		}

		$bot         = $this->bots->find( $bot_id );
		$destination = $this->destinations->find( $destination_id );

		if ( null === $bot || null === $destination ) {
			throw new RuntimeException( 'telegram_send_missing_bot_or_destination' );
		}

		if ( $this->is_past_pending_ceiling( $message ) ) {
			$this->dead_letter( $message->id(), $bot_id, $destination_id, 'telegram_pending_ceiling_exceeded' );
			return;
		}

		$outcome = $this->try_once( $message, $bot, $destination, (int) $job['attempt'] );

		if ( AttemptOutcome::ALREADY_CLAIMED === $outcome ) {
			$this->reschedule_after_observed_lease( $message, $job );
			return;
		}

		if ( AttemptOutcome::PENDING_RETRY === $outcome ) {
			throw new RuntimeException( 'telegram_send_failed' );
		}

		// DELIVERED, DEFERRED, TERMINAL: already fully handled, no throw.
	}

	/**
	 * One non-throwing delivery attempt, shared unmodified between this
	 * queue handler and the in-process immediate/fallback attempt layers
	 * (M06.2 corrective plan v2 §3.1–§3.2, ADR-0023 amendment). Never
	 * schedules a durable retry itself for a RETRYABLE outcome — that
	 * remains the caller's own concern (handle_job's throw drives
	 * WorkerRunner's generic backoff; an in-process caller simply treats
	 * anything other than DELIVERED as "not delivered yet").
	 *
	 * @param OutboundMessage $message     The message to attempt.
	 * @param BotProfile      $bot         The owning bot.
	 * @param Destination     $destination The target destination.
	 * @param int             $attempt     The current attempt number, for exhaustion accounting only.
	 *
	 * @return AttemptOutcome
	 *
	 * @throws RuntimeException If the bot's token or the message body cannot be decrypted — a
	 *                           genuine configuration error, not an ordinary delivery outcome.
	 */
	public function try_once( OutboundMessage $message, BotProfile $bot, Destination $destination, int $attempt ): AttemptOutcome {
		if ( in_array( $message->status(), array( OutboundMessageStatus::SENT, OutboundMessageStatus::DEAD_LETTER, OutboundMessageStatus::PURGED ), true ) ) {
			// Already resolved by another claimant (or a prior call) — a
			// terminal status is never re-claimable, so treat this as a
			// clean no-op rather than repeatedly discovering ALREADY_CLAIMED
			// against a lease that will never again advance.
			return OutboundMessageStatus::SENT === $message->status() ? AttemptOutcome::DELIVERED : AttemptOutcome::TERMINAL;
		}

		$bot_id         = $bot->id();
		$destination_id = $destination->id();

		try {
			$this->circuit_breaker->assert_may_attempt( 'bot', $bot_id );
		} catch ( CircuitOpenException $exception ) {
			return $this->defer_or_dead_letter( $message, $bot_id, $destination_id, $exception, 'telegram_bot_circuit_open' );
		}

		if ( ! $this->rate_limiter->try_consume( 'bot', $bot_id, self::BOT_RATE_CAPACITY, self::BOT_RATE_REFILL_PER_SECOND ) ) {
			$this->defer_locally( $message, $attempt, 'telegram_bot_rate_limited' );
			return AttemptOutcome::DEFERRED;
		}

		try {
			$this->circuit_breaker->assert_may_attempt( 'destination', $destination_id );
		} catch ( CircuitOpenException $exception ) {
			return $this->defer_or_dead_letter( $message, $bot_id, $destination_id, $exception, 'telegram_destination_circuit_open' );
		}

		if ( ! $this->rate_limiter->try_consume( 'destination', $destination_id, self::DESTINATION_RATE_CAPACITY, self::DESTINATION_RATE_REFILL ) ) {
			$this->defer_locally( $message, $attempt, 'telegram_destination_rate_limited' );
			return AttemptOutcome::DEFERRED;
		}

		if ( $this->is_group_kind( $destination )
			&& ! $this->rate_limiter->try_consume( 'destination_group', $destination_id, self::GROUP_RATE_CAPACITY, self::GROUP_RATE_REFILL_PER_SECOND ) ) {
			$this->defer_locally( $message, $attempt, 'telegram_destination_group_rate_limited' );
			return AttemptOutcome::DEFERRED;
		}

		$token_result = $this->bots->decrypt_token( $bot );

		if ( CredentialState::AVAILABLE !== $token_result->state() || null === $token_result->plaintext() ) {
			throw new RuntimeException( 'telegram_send_token_unavailable' );
		}

		$body_result = $this->messages->decrypt_body( $message );

		if ( null === $body_result || CredentialState::AVAILABLE !== $body_result->state() || null === $body_result->plaintext() ) {
			throw new RuntimeException( 'telegram_send_body_unavailable' );
		}

		if ( ! $this->messages->try_claim_for_sending( $message->id() ) ) {
			return AttemptOutcome::ALREADY_CLAIMED;
		}

		$result = $this->client->send_message(
			$token_result->plaintext(),
			$destination->chat_id(),
			$body_result->plaintext(),
			$destination->message_thread_id(),
			$message->parse_mode()
		);

		if ( $result->ok() ) {
			$this->circuit_breaker->record_success( 'bot', $bot_id );
			$this->circuit_breaker->record_success( 'destination', $destination_id );

			$telegram_message_id = isset( $result->result()['message_id'] ) && is_int( $result->result()['message_id'] )
				? $result->result()['message_id']
				: null;

			$this->messages->mark_sent( $message->id(), $telegram_message_id );
			return AttemptOutcome::DELIVERED;
		}

		if ( $result->is_network_error() ) {
			$this->messages->set_possible_duplicate_delivery( $message->id() );
		}

		return $this->handle_failure( $message, $bot, $destination, $attempt, $result );
	}

	/**
	 * Dispatches a failed result to its classified handling path.
	 *
	 * @param OutboundMessage    $message     The message.
	 * @param BotProfile         $bot         The bot.
	 * @param Destination        $destination The destination.
	 * @param int                $attempt     The current attempt number.
	 * @param TelegramApiResult  $result      The failed API result.
	 *
	 * @return AttemptOutcome
	 */
	private function handle_failure( OutboundMessage $message, BotProfile $bot, Destination $destination, int $attempt, TelegramApiResult $result ): AttemptOutcome {
		$classification = $this->classifier->classify( $result );

		if ( FailureClassification::RATE_LIMITED === $classification ) {
			$wait = $result->retry_after() ?? $this->rate_limit_fallback_wait_seconds;
			$this->messages->release_claim_for_retry( $message->id() );
			$this->reschedule_job( $message, $attempt, $wait );
			return AttemptOutcome::DEFERRED;
		}

		if ( FailureClassification::TERMINAL === $classification ) {
			$this->dead_letter( $message->id(), $bot->id(), $destination->id(), 'telegram_terminal_rejection' );
			return AttemptOutcome::TERMINAL;
		}

		if ( FailureClassification::TOKEN_INVALID === $classification ) {
			$this->circuit_breaker->open_indefinitely( 'bot', $bot->id() );
			$this->bots->set_status( $bot->id(), BotStatus::INVALID );
			$this->dead_letter( $message->id(), $bot->id(), $destination->id(), 'telegram_token_invalid' );
			return AttemptOutcome::TERMINAL;
		}

		// RETRYABLE.
		$this->circuit_breaker->record_failure( 'bot', $bot->id(), self::BOT_CIRCUIT_THRESHOLD, self::CIRCUIT_WINDOW_SECONDS );
		$this->circuit_breaker->record_failure( 'destination', $destination->id(), self::DESTINATION_CIRCUIT_THRESHOLD, self::CIRCUIT_WINDOW_SECONDS );

		if ( $attempt >= $this->retry_policy->max_attempts() ) {
			$this->dead_letter( $message->id(), $bot->id(), $destination->id(), 'telegram_retryable_attempts_exhausted' );
			return AttemptOutcome::TERMINAL;
		}

		$this->messages->mark_retry_scheduled( $message->id() );
		return AttemptOutcome::PENDING_RETRY;
	}

	/**
	 * Whether a message has been pending longer than the unbounded-deferral
	 * safety ceiling.
	 *
	 * @param OutboundMessage $message The message.
	 *
	 * @return bool
	 */
	private function is_past_pending_ceiling( OutboundMessage $message ): bool {
		$created_at = strtotime( $message->created_at() . ' UTC' );

		return false !== $created_at && ( time() - $created_at ) > $this->max_pending_seconds;
	}

	/**
	 * Whether a destination's kind participates in the extra group/supergroup
	 * rate-limit bucket.
	 *
	 * @param Destination $destination The destination.
	 *
	 * @return bool
	 */
	private function is_group_kind( Destination $destination ): bool {
		return DestinationKind::GROUP === $destination->kind() || DestinationKind::SUPERGROUP === $destination->kind();
	}

	/**
	 * Defers via a fresh Action Scheduler action at a circuit breaker's own
	 * next_probe_at, or dead-letters immediately if the breaker is open
	 * indefinitely (TOKEN_INVALID, no scheduled probe).
	 *
	 * @param OutboundMessage      $message        The message.
	 * @param int                  $bot_id         The bot's primary key.
	 * @param int                  $destination_id The destination's primary key.
	 * @param CircuitOpenException $exception      The breaker's own refusal.
	 * @param string               $reason_code     A fixed stable code for dead-letter/audit use.
	 *
	 * @return AttemptOutcome
	 */
	private function defer_or_dead_letter( OutboundMessage $message, int $bot_id, int $destination_id, CircuitOpenException $exception, string $reason_code ): AttemptOutcome {
		if ( null === $exception->next_probe_at() ) {
			$this->dead_letter( $message->id(), $bot_id, $destination_id, $reason_code );
			return AttemptOutcome::TERMINAL;
		}

		$this->defer_locally( $message, 1, $reason_code, $exception->next_probe_at() );
		return AttemptOutcome::DEFERRED;
	}

	/**
	 * Non-throwing, budget-preserving deferral: reschedules the same
	 * (unincremented-attempt) job at a future time via Action Scheduler's
	 * own public scheduling function.
	 *
	 * @param OutboundMessage $message     The message, left untouched (still pending).
	 * @param int             $attempt     The attempt number to carry into the rescheduled job.
	 * @param string          $reason_code A fixed stable code, audited only.
	 * @param int|null        $at          The absolute timestamp to run at; defaults to a short local wait.
	 */
	private function defer_locally( OutboundMessage $message, int $attempt, string $reason_code, ?int $at = null ): void {
		$args = array(
			array(
				'job_id'   => $message->message_uuid(),
				'job_type' => MessageDispatcher::JOB_TYPE,
				'attempt'  => $attempt,
				'payload'  => array(
					'message_uuid'   => $message->message_uuid(),
					'bot_id'         => $message->bot_id(),
					'destination_id' => $message->destination_id(),
				),
			),
		);

		as_schedule_single_action( $at ?? ( time() + self::LOCAL_RATE_LIMIT_DEFER_SECONDS ), WorkerRunner::HOOK, $args, WorkerRunner::GROUP );

		$this->audit_logger->record(
			$reason_code,
			'system',
			null,
			array( 'message_id' => $message->id() ),
			array( 'message_id' => Classification::INTERNAL ),
			Classification::INTERNAL
		);
	}

	/**
	 * Reschedules the same (unincremented-attempt) job at a given delay,
	 * honoring a Telegram-provided flood-control wait.
	 *
	 * @param OutboundMessage $message      The message.
	 * @param int             $attempt      The attempt number to carry into the rescheduled job.
	 * @param int             $wait_seconds The delay, in seconds.
	 */
	private function reschedule_job( OutboundMessage $message, int $attempt, int $wait_seconds ): void {
		$args = array(
			array(
				'job_id'   => $message->message_uuid(),
				'job_type' => MessageDispatcher::JOB_TYPE,
				'attempt'  => $attempt,
				'payload'  => array(
					'message_uuid'   => $message->message_uuid(),
					'bot_id'         => $message->bot_id(),
					'destination_id' => $message->destination_id(),
				),
			),
		);

		as_schedule_single_action( time() + $wait_seconds, WorkerRunner::HOOK, $args, WorkerRunner::GROUP );
	}

	/**
	 * Reads the other claimant's observed lease expiry and self-reschedules
	 * this job to check back exactly once, just after that lease should
	 * have expired — never busy-polling, never dropping the job silently
	 * (M06.2 corrective plan v2 §3.1, ADR-0023 amendment).
	 *
	 * @param OutboundMessage                                                                     $message The message currently claimed by another caller.
	 * @param array{job_id: string, job_type: string, attempt: int, payload: array<string, mixed>} $job     The current job, rescheduled unchanged.
	 */
	private function reschedule_after_observed_lease( OutboundMessage $message, array $job ): void {
		$lease_expiry = $message->claim_expires_at();
		$at           = null !== $lease_expiry ? strtotime( $lease_expiry . ' UTC' ) + 1 : time() + 1;

		as_schedule_single_action( $at, WorkerRunner::HOOK, array( $job ), WorkerRunner::GROUP );
	}

	/**
	 * Transitions a message to dead_letter and records a Telegram-specific
	 * audit entry with delivery context.
	 *
	 * @param int    $message_id      The message's primary key.
	 * @param int    $bot_id          The bot's primary key.
	 * @param int    $destination_id  The destination's primary key.
	 * @param string $reason_code      A fixed stable code, never raw API text.
	 */
	private function dead_letter( int $message_id, int $bot_id, int $destination_id, string $reason_code ): void {
		$this->messages->mark_dead_letter( $message_id, $reason_code );

		$this->audit_logger->record(
			'telegram_message_dead_lettered',
			'system',
			null,
			array(
				'message_id'     => $message_id,
				'bot_id'         => $bot_id,
				'destination_id' => $destination_id,
				'reason_code'    => $reason_code,
			),
			array(
				'message_id'     => Classification::INTERNAL,
				'bot_id'         => Classification::INTERNAL,
				'destination_id' => Classification::INTERNAL,
				'reason_code'    => Classification::INTERNAL,
			),
			Classification::INTERNAL
		);
	}
}
