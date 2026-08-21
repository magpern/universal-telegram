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
use UniversalTelegram\Queue\RetryPolicy;
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
	 * The Action Scheduler job handler.
	 *
	 * @param array{job_id: string, job_type: string, attempt: int, payload: array<string, mixed>} $job The job.
	 *
	 * @throws RuntimeException On a retryable failure with attempts remaining, so WorkerRunner's own generic retry sequence runs.
	 */
	public function handle_job( array $job ): void {
		$conversation_id = (int) $job['payload']['conversation_id'];
		$conversation     = $this->conversations->find( $conversation_id );

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

		$bot = $this->bots->find( $conversation->bot_id() );

		if ( null === $bot ) {
			$this->fail_or_retry( $job, $conversation_id, 'conversation_topic_bot_missing' );
			return;
		}

		$chat_id = $this->chat_profiles->conversation_chat_id( $bot->id() );

		if ( null === $chat_id ) {
			$this->fail_or_retry( $job, $conversation_id, 'conversation_topic_chat_not_configured' );
			return;
		}

		$token_result = $this->bots->decrypt_token( $bot );

		if ( CredentialState::AVAILABLE !== $token_result->state() || null === $token_result->plaintext() ) {
			$this->fail_or_retry( $job, $conversation_id, 'conversation_topic_token_unavailable' );
			return;
		}

		$result = $this->client->create_forum_topic(
			$token_result->plaintext(),
			$chat_id,
			'Conversation ' . $conversation->conversation_uuid()
		);

		if ( ! $result->ok() ) {
			$this->fail_or_retry( $job, $conversation_id, 'conversation_topic_creation_failed' );
			return;
		}

		$telegram_topic_id = isset( $result->result()['message_thread_id'] ) && is_int( $result->result()['message_thread_id'] )
			? $result->result()['message_thread_id']
			: null;

		if ( null === $telegram_topic_id ) {
			$this->fail_or_retry( $job, $conversation_id, 'conversation_topic_response_malformed' );
			return;
		}

		$destination = $this->destinations->create(
			$bot->id(),
			DestinationKind::SUPERGROUP,
			$chat_id,
			$telegram_topic_id,
			'Conversation ' . $conversation->conversation_uuid()
		);

		if ( null === $destination ) {
			$this->fail_or_retry( $job, $conversation_id, 'conversation_topic_destination_write_failed' );
			return;
		}

		$this->conversations->mark_topic_created( $conversation_id, $telegram_topic_id, $destination->id() );
	}

	/**
	 * Marks the conversation's topic creation 'failed' once the retry
	 * budget is exhausted; otherwise rethrows so WorkerRunner's generic
	 * retry sequence runs.
	 *
	 * @param array{job_id: string, job_type: string, attempt: int, payload: array<string, mixed>} $job              The job.
	 * @param int                                                                                  $conversation_id  The conversation's primary key.
	 * @param string                                                                               $reason_code       A fixed stable code (unused beyond the thrown exception's message).
	 *
	 * @throws RuntimeException If attempts remain.
	 */
	private function fail_or_retry( array $job, int $conversation_id, string $reason_code ): void {
		if ( (int) $job['attempt'] >= $this->retry_policy->max_attempts() ) {
			$this->conversations->mark_topic_failed( $conversation_id );
			return;
		}

		throw new RuntimeException( $reason_code );
	}
}
