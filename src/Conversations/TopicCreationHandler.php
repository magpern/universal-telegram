<?php
/**
 * Telegram forum-topic creation handler.
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
use UniversalTelegram\Telegram\Client\TelegramApiClient;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;
use UniversalTelegram\Telegram\Configuration\DestinationKind;
use UniversalTelegram\Telegram\Configuration\DestinationRepository;

/**
 * The queue's registered handler for TopicCreationHandler::JOB_TYPE,
 * enqueued only by TopicCreationDispatcher after it wins
 * ConversationRepository::try_begin_topic_creation()'s compare-and-set.
 * Mirrors Telegram\Outbound\SendMessageHandler's shape: bounded retries
 * (Queue\RetryPolicy's existing attempt cap) end in
 * topic_creation_state='failed', a surfaced degraded state, never a silent
 * drop (M05 plan §5, docs/adr/0021).
 */
class TopicCreationHandler {

	public const JOB_TYPE = 'conversation_create_topic';

	/**
	 * Constructor.
	 *
	 * @param ConversationRepository $conversations Owns the conversation's topic-creation state.
	 * @param BotProfileRepository   $bots          Bot profiles, including token decryption.
	 * @param ChatProfileResolver    $chat_profiles Resolves the bot's conversation-support chat id.
	 * @param DestinationRepository  $destinations  Creates the conversation's own destination row.
	 * @param TelegramApiClient      $client        The Telegram Bot API client.
	 * @param RetryPolicy            $retry_policy  Consulted only for its own max_attempts().
	 */
	public function __construct(
		private readonly ConversationRepository $conversations,
		private readonly BotProfileRepository $bots,
		private readonly ChatProfileResolver $chat_profiles,
		private readonly DestinationRepository $destinations,
		private readonly TelegramApiClient $client,
		private readonly RetryPolicy $retry_policy
	) {}

	/**
	 * The Action Scheduler job handler. A thin wrapper around try_once():
	 * resolves and verifies the conversation still belongs to this job's own
	 * claim window, then translates the shared AttemptOutcome into this
	 * queue's own existing scheduling behavior (M06.2 corrective plan v2
	 * §3.1–§3.2, ADR-0023 amendment).
	 *
	 * @param array{job_id: string, job_type: string, attempt: int, payload: array<string, mixed>} $job The job.
	 *
	 * @throws RuntimeException On a retryable failure with attempts remaining, so WorkerRunner's own generic retry sequence runs.
	 */
	public function handle_job( array $job ): void {
		$conversation_id = (int) $job['payload']['conversation_id'];
		$conversation    = $this->conversations->find( $conversation_id );

		if ( null === $conversation ) {
			// Deleted by retention or never existed; nothing to create a
			// topic for. Terminal, not retryable.
			return;
		}

		if ( 'pending' !== $conversation->topic_creation_state() ) {
			// Already resolved (created/failed) or never began — this job
			// is a stale duplicate; the compare-and-set guard already
			// prevented a second topic from ever being created.
			return;
		}

		// Verifies this specific job still owns the claim window it was
		// enqueued under: a later caller may have reclaimed after this
		// job's own lease expired (crash recovery). Payloads enqueued
		// before this field existed carry no verification data and are
		// treated as still-owning, preserving prior behavior exactly.
		$claimed_lease_expires_at = $job['payload']['claimed_lease_expires_at'] ?? null;

		if ( null !== $claimed_lease_expires_at && $claimed_lease_expires_at !== $conversation->topic_claim_expires_at() ) {
			$this->reschedule_after_observed_lease( $conversation, $job );
			return;
		}

		$outcome = $this->try_once( $conversation, (int) $job['attempt'] );

		if ( AttemptOutcome::PENDING_RETRY === $outcome ) {
			throw new RuntimeException( 'conversation_topic_creation_failed_retry' );
		}

		// DELIVERED, TERMINAL: already fully handled, no throw.
	}

	/**
	 * One non-throwing topic-creation attempt, shared unmodified between
	 * this queue handler and the in-process immediate attempt layer
	 * (M06.2 corrective plan v2 §3.1–§3.2, ADR-0023 amendment). Never
	 * schedules a durable retry itself — that remains the caller's own
	 * concern (handle_job's throw drives WorkerRunner's generic backoff; an
	 * in-process caller simply treats anything other than DELIVERED as
	 * "not delivered yet").
	 *
	 * @param Conversation $conversation The conversation, with topic_creation_state already verified 'pending' and this call's own claim window confirmed current.
	 * @param int          $attempt      The current attempt number, for exhaustion accounting only.
	 *
	 * @return AttemptOutcome
	 */
	public function try_once( Conversation $conversation, int $attempt ): AttemptOutcome {
		$conversation_id = $conversation->id();
		$bot             = $this->bots->find( $conversation->bot_id() );

		if ( null === $bot ) {
			return $this->fail_or_retry( $conversation_id, $attempt );
		}

		$chat_id = $this->chat_profiles->conversation_chat_id( $bot->id() );

		if ( null === $chat_id ) {
			return $this->fail_or_retry( $conversation_id, $attempt );
		}

		$token_result = $this->bots->decrypt_token( $bot );

		if ( CredentialState::AVAILABLE !== $token_result->state() || null === $token_result->plaintext() ) {
			return $this->fail_or_retry( $conversation_id, $attempt );
		}

		$result = $this->client->create_forum_topic(
			$token_result->plaintext(),
			$chat_id,
			'Conversation ' . $conversation->conversation_uuid()
		);

		if ( ! $result->ok() ) {
			return $this->fail_or_retry( $conversation_id, $attempt );
		}

		$telegram_topic_id = isset( $result->result()['message_thread_id'] ) && is_int( $result->result()['message_thread_id'] )
			? $result->result()['message_thread_id']
			: null;

		if ( null === $telegram_topic_id ) {
			return $this->fail_or_retry( $conversation_id, $attempt );
		}

		$destination = $this->destinations->create(
			$bot->id(),
			DestinationKind::SUPERGROUP,
			$chat_id,
			$telegram_topic_id,
			'Conversation ' . $conversation->conversation_uuid()
		);

		if ( null === $destination ) {
			return $this->fail_or_retry( $conversation_id, $attempt );
		}

		$this->conversations->mark_topic_created( $conversation_id, $telegram_topic_id, $destination->id() );

		return AttemptOutcome::DELIVERED;
	}

	/**
	 * Marks the conversation's topic creation 'failed' once the retry
	 * budget is exhausted; otherwise reports PENDING_RETRY so handle_job's
	 * own throw drives WorkerRunner's generic retry sequence.
	 *
	 * @param int $conversation_id The conversation's primary key.
	 * @param int $attempt         The current attempt number.
	 *
	 * @return AttemptOutcome
	 */
	private function fail_or_retry( int $conversation_id, int $attempt ): AttemptOutcome {
		if ( $attempt >= $this->retry_policy->max_attempts() ) {
			$this->conversations->mark_topic_failed( $conversation_id );
			return AttemptOutcome::TERMINAL;
		}

		return AttemptOutcome::PENDING_RETRY;
	}

	/**
	 * Reads the current, superseding claimant's observed lease expiry and
	 * self-reschedules this job to check back exactly once, just after that
	 * lease should have expired, rather than attempting a second, competing
	 * `createForumTopic` call (M06.2 corrective plan v2 §3.1, ADR-0023
	 * amendment).
	 *
	 * @param Conversation                                                                        $conversation The conversation, now owned by a different claim window.
	 * @param array{job_id: string, job_type: string, attempt: int, payload: array<string, mixed>} $job          The current job, rescheduled unchanged.
	 */
	private function reschedule_after_observed_lease( Conversation $conversation, array $job ): void {
		$lease_expiry = $conversation->topic_claim_expires_at();
		$at           = null !== $lease_expiry ? strtotime( $lease_expiry . ' UTC' ) + 1 : time() + 1;

		as_schedule_single_action( $at, WorkerRunner::HOOK, array( $job ), WorkerRunner::GROUP );
	}
}
