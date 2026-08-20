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
	 * The Action Scheduler job handler.
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

		try {
			$this->circuit_breaker->assert_may_attempt( 'bot', $bot_id );
		} catch ( CircuitOpenException $exception ) {
			$this->defer_or_dead_letter( $message, $bot_id, $destination_id, $exception, 'telegram_bot_circuit_open' );
			return;
		}

		if ( ! $this->rate_limiter->try_consume( 'bot', $bot_id, self::BOT_RATE_CAPACITY, self::BOT_RATE_REFILL_PER_SECOND ) ) {
			$this->defer_locally( $message, 'telegram_bot_rate_limited' );
			return;
		}

		try {
			$this->circuit_breaker->assert_may_attempt( 'destination', $destination_id );
		} catch ( CircuitOpenException $exception ) {
			$this->defer_or_dead_letter( $message, $bot_id, $destination_id, $exception, 'telegram_destination_circuit_open' );
			return;
		}

		if ( ! $this->rate_limiter->try_consume( 'destination', $destination_id, self::DESTINATION_RATE_CAPACITY, self::DESTINATION_RATE_REFILL ) ) {
			$this->defer_locally( $message, 'telegram_destination_rate_limited' );
			return;
		}

		if ( $this->is_group_kind( $destination )
			&& ! $this->rate_limiter->try_consume( 'destination_group', $destination_id, self::GROUP_RATE_CAPACITY, self::GROUP_RATE_REFILL_PER_SECOND ) ) {
			$this->defer_locally( $message, 'telegram_destination_group_rate_limited' );
			return;
		}

		$token_result = $this->bots->decrypt_token( $bot );

		if ( CredentialState::AVAILABLE !== $token_result->state() || null === $token_result->plaintext() ) {
			throw new RuntimeException( 'telegram_send_token_unavailable' );
		}

		$body_result = $this->messages->decrypt_body( $message );

		if ( null === $body_result || CredentialState::AVAILABLE !== $body_result->state() || null === $body_result->plaintext() ) {
			throw new RuntimeException( 'telegram_send_body_unavailable' );
		}

		$this->messages->mark_sending( $message->id() );

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
			return;
		}

		if ( $result->is_network_error() ) {
			$this->messages->set_possible_duplicate_delivery( $message->id() );
		}

		$this->handle_failure( $job, $message, $bot, $destination, $result );
	}

	/**
	 * Dispatches a failed result to its classified handling path.
	 *
	 * @param array{job_id: string, job_type: string, attempt: int, payload: array<string, mixed>} $job         The job.
	 * @param OutboundMessage                                                                      $message     The message.
	 * @param BotProfile                                                                           $bot         The bot.
	 * @param Destination                                                                          $destination The destination.
	 * @param TelegramApiResult                                                                    $result      The failed API result.
	 *
	 * @throws RuntimeException On RETRYABLE, so the generic retry sequence runs.
	 */
	private function handle_failure( array $job, OutboundMessage $message, BotProfile $bot, Destination $destination, TelegramApiResult $result ): void {
		$classification = $this->classifier->classify( $result );

		if ( FailureClassification::RATE_LIMITED === $classification ) {
			$wait = $result->retry_after() ?? $this->rate_limit_fallback_wait_seconds;
			$this->reschedule_job( $job, $wait );
			return;
		}

		if ( FailureClassification::TERMINAL === $classification ) {
			$this->dead_letter( $message->id(), $bot->id(), $destination->id(), 'telegram_terminal_rejection' );
			return;
		}

		if ( FailureClassification::TOKEN_INVALID === $classification ) {
			$this->circuit_breaker->open_indefinitely( 'bot', $bot->id() );
			$this->bots->set_status( $bot->id(), BotStatus::INVALID );
			$this->dead_letter( $message->id(), $bot->id(), $destination->id(), 'telegram_token_invalid' );
			return;
		}

		// RETRYABLE.
		$this->circuit_breaker->record_failure( 'bot', $bot->id(), self::BOT_CIRCUIT_THRESHOLD, self::CIRCUIT_WINDOW_SECONDS );
		$this->circuit_breaker->record_failure( 'destination', $destination->id(), self::DESTINATION_CIRCUIT_THRESHOLD, self::CIRCUIT_WINDOW_SECONDS );

		if ( (int) $job['attempt'] >= $this->retry_policy->max_attempts() ) {
			$this->dead_letter( $message->id(), $bot->id(), $destination->id(), 'telegram_retryable_attempts_exhausted' );
		} else {
			$this->messages->mark_retry_scheduled( $message->id() );
		}

		throw new RuntimeException( 'telegram_send_failed' );
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
	 */
	private function defer_or_dead_letter( OutboundMessage $message, int $bot_id, int $destination_id, CircuitOpenException $exception, string $reason_code ): void {
		if ( null === $exception->next_probe_at() ) {
			$this->dead_letter( $message->id(), $bot_id, $destination_id, $reason_code );
			return;
		}

		$this->defer_locally( $message, $reason_code, $exception->next_probe_at() );
	}

	/**
	 * Non-throwing, budget-preserving deferral: reschedules the same
	 * (unincremented-attempt) job at a future time via Action Scheduler's
	 * own public scheduling function.
	 *
	 * @param OutboundMessage $message   The message, left untouched (still pending).
	 * @param string          $reason_code A fixed stable code, audited only.
	 * @param int|null        $at          The absolute timestamp to run at; defaults to a short local wait.
	 */
	private function defer_locally( OutboundMessage $message, string $reason_code, ?int $at = null ): void {
		$args = array(
			array(
				'job_id'   => $message->message_uuid(),
				'job_type' => MessageDispatcher::JOB_TYPE,
				'attempt'  => 1,
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
	 * Reschedules the current job (via WorkerRunner's own hook/group) at a
	 * given delay, honoring a Telegram-provided flood-control wait.
	 *
	 * @param array{job_id: string, job_type: string, attempt: int, payload: array<string, mixed>} $job The job to reschedule.
	 * @param int                                                                                  $wait_seconds The delay, in seconds.
	 */
	private function reschedule_job( array $job, int $wait_seconds ): void {
		as_schedule_single_action( time() + $wait_seconds, WorkerRunner::HOOK, array( $job ), WorkerRunner::GROUP );
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
