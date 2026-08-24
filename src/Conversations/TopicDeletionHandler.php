<?php
/**
 * Queued Telegram forum-topic deletion handler.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Conversations;

use RuntimeException;
use UniversalTelegram\Core\Security\CredentialState;
use UniversalTelegram\Queue\AttemptOutcome;
use UniversalTelegram\Queue\RetryPolicy;
use UniversalTelegram\Queue\WorkerRunner;
use UniversalTelegram\Telegram\Client\FailureClassification;
use UniversalTelegram\Telegram\Client\TelegramApiClient;
use UniversalTelegram\Telegram\Client\TelegramFailureClassifier;
use UniversalTelegram\Telegram\Client\TelegramTopicError;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;

/**
 * Registered handler for conversation_delete_topic. Calls deleteForumTopic
 * only for ConversationTopicEligibility-approved destinations, then purges
 * locally on success or explicit missing-topic. Never purges on chat-not-found
 * (M07.1, docs/adr/0031).
 */
class TopicDeletionHandler {

	public const JOB_TYPE = 'conversation_delete_topic';

	/**
	 * Constructor.
	 *
	 * @param ConversationRepository       $conversations Conversation persistence.
	 * @param BotProfileRepository         $bots          Bot profiles and token decryption.
	 * @param ConversationTopicEligibility $eligibility   Structural remote-delete gate.
	 * @param ConversationPurgeService     $purge         Sole local destructor.
	 * @param TelegramApiClient            $client        Telegram Bot API client.
	 * @param RetryPolicy                  $retry_policy  Attempt budget.
	 * @param TelegramFailureClassifier    $classifier    HTTP/network failure classification.
	 */
	public function __construct(
		private readonly ConversationRepository $conversations,
		private readonly BotProfileRepository $bots,
		private readonly ConversationTopicEligibility $eligibility,
		private readonly ConversationPurgeService $purge,
		private readonly TelegramApiClient $client,
		private readonly RetryPolicy $retry_policy,
		private readonly TelegramFailureClassifier $classifier = new TelegramFailureClassifier()
	) {}

	/**
	 * Action Scheduler job entrypoint.
	 *
	 * @param array{job_id: string, job_type: string, attempt: int, payload: array<string, mixed>} $job The job.
	 *
	 * @throws RuntimeException On retryable failure with attempts remaining.
	 */
	public function handle_job( array $job ): void {
		$conversation_id = (int) $job['payload']['conversation_id'];
		$conversation    = $this->conversations->find( $conversation_id );

		if ( null === $conversation ) {
			return;
		}

		if ( TopicLifecycleState::DELETE_PENDING !== $conversation->topic_lifecycle_state() ) {
			return;
		}

		$claimed_lease_expires_at = $job['payload']['claimed_lease_expires_at'] ?? null;

		if ( null !== $claimed_lease_expires_at && $claimed_lease_expires_at !== $conversation->topic_delete_claim_expires_at() ) {
			$this->reschedule_after_observed_lease( $conversation, $job );
			return;
		}

		$outcome = $this->try_once( $conversation, (int) $job['attempt'] );

		if ( AttemptOutcome::PENDING_RETRY === $outcome ) {
			throw new RuntimeException( 'conversation_topic_deletion_failed_retry' );
		}
	}

	/**
	 * One non-throwing remote-delete attempt.
	 *
	 * @param Conversation $conversation Conversation already verified delete_pending.
	 * @param int          $attempt      Current attempt number.
	 *
	 * @return AttemptOutcome
	 */
	public function try_once( Conversation $conversation, int $attempt ): AttemptOutcome {
		$destination = $this->eligibility->eligible_destination( $conversation );

		if ( null === $destination ) {
			$this->purge->purge( $conversation->id(), null );
			return AttemptOutcome::TERMINAL;
		}

		$bot = $this->bots->find( $conversation->bot_id() );

		if ( null === $bot ) {
			return $this->fail_or_retry( $conversation->id(), $attempt, TelegramTopicError::TOPIC_DELETE_ATTEMPTS_EXHAUSTED );
		}

		$token_result = $this->bots->decrypt_token( $bot );

		if ( CredentialState::AVAILABLE !== $token_result->state() || null === $token_result->plaintext() ) {
			$this->conversations->mark_topic_lifecycle(
				$conversation->id(),
				TopicLifecycleState::DELETE_FAILED,
				'telegram_token_invalid'
			);
			return AttemptOutcome::TERMINAL;
		}

		$result = $this->client->delete_forum_topic(
			$token_result->plaintext(),
			$destination->chat_id(),
			(int) $destination->message_thread_id()
		);

		if ( $result->ok() || TelegramTopicError::is_missing_topic_on_delete( $result ) ) {
			$this->purge->purge( $conversation->id(), $destination->id() );
			return AttemptOutcome::DELIVERED;
		}

		$code           = TelegramTopicError::classify_delete_failure( $result );
		$classification = $this->classifier->classify( $result );

		if ( FailureClassification::TOKEN_INVALID === $classification
			|| TelegramTopicError::TOPIC_DELETE_CHAT_NOT_FOUND === $code
			|| TelegramTopicError::TOPIC_DELETE_FORBIDDEN === $code
		) {
			$this->conversations->mark_topic_lifecycle(
				$conversation->id(),
				TopicLifecycleState::DELETE_FAILED,
				FailureClassification::TOKEN_INVALID === $classification ? 'telegram_token_invalid' : $code
			);
			return AttemptOutcome::TERMINAL;
		}

		if ( FailureClassification::RETRYABLE === $classification
			|| FailureClassification::RATE_LIMITED === $classification
		) {
			return $this->fail_or_retry( $conversation->id(), $attempt, TelegramTopicError::TOPIC_DELETE_ATTEMPTS_EXHAUSTED );
		}

		$this->conversations->mark_topic_lifecycle(
			$conversation->id(),
			TopicLifecycleState::DELETE_FAILED,
			$code
		);

		return AttemptOutcome::TERMINAL;
	}

	/**
	 * Marks delete_failed once attempts are exhausted; otherwise PENDING_RETRY.
	 *
	 * @param int    $conversation_id Conversation primary key.
	 * @param int    $attempt         Current attempt number.
	 * @param string $exhausted_code  Fixed code when budget is spent.
	 *
	 * @return AttemptOutcome
	 */
	private function fail_or_retry( int $conversation_id, int $attempt, string $exhausted_code ): AttemptOutcome {
		if ( $attempt >= $this->retry_policy->max_attempts() ) {
			$this->conversations->mark_topic_lifecycle(
				$conversation_id,
				TopicLifecycleState::DELETE_FAILED,
				$exhausted_code
			);
			return AttemptOutcome::TERMINAL;
		}

		return AttemptOutcome::PENDING_RETRY;
	}

	/**
	 * Reschedules the job just after an observed competing claim lease.
	 *
	 * @param Conversation                                                                         $conversation Conversation owned by another claim.
	 * @param array{job_id: string, job_type: string, attempt: int, payload: array<string, mixed>} $job          Current job.
	 */
	private function reschedule_after_observed_lease( Conversation $conversation, array $job ): void {
		$lease_expiry = $conversation->topic_delete_claim_expires_at();
		$at           = null !== $lease_expiry ? strtotime( $lease_expiry . ' UTC' ) + 1 : time() + 1;

		as_schedule_single_action( $at, WorkerRunner::HOOK, array( $job ), WorkerRunner::GROUP );
	}
}
