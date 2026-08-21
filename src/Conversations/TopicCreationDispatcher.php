<?php
/**
 * Idempotent Telegram forum-topic creation dispatch.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Conversations;

use UniversalTelegram\Privacy\Classification;
use UniversalTelegram\Queue\Dispatcher;
use UniversalTelegram\Queue\DispatchResult;
use UniversalTelegram\Queue\JobEnvelope;

/**
 * The only caller that may enqueue a TopicCreationHandler job. Mirrors
 * Telegram\Outbound\MessageDispatcher's own owns-the-write-then-enqueue
 * shape, but the "write" here is
 * ConversationRepository::try_begin_topic_creation()'s own atomic
 * compare-and-set — the mechanism that makes calling maybe_create() an
 * unbounded number of times for the same conversation still enqueue at
 * most one job (M05 plan §5, docs/adr/0021).
 */
final class TopicCreationDispatcher {

	/**
	 * Constructor.
	 *
	 * @param ConversationRepository $conversations Owns the compare-and-set guard.
	 * @param Dispatcher             $dispatcher    M00's generic queue dispatcher, used as-is.
	 */
	public function __construct(
		private readonly ConversationRepository $conversations,
		private readonly Dispatcher $dispatcher
	) {}

	/**
	 * Attempts to begin topic creation for a conversation. A no-op, safe to
	 * call on every accepted visitor message, including retries and
	 * duplicate first-message submissions: only the call that wins the
	 * underlying compare-and-set actually enqueues a job.
	 *
	 * @param Conversation $conversation The conversation whose first message was just accepted.
	 *
	 * @return DispatchResult|null Null if this call did not win the guard (already pending/created/failed).
	 */
	public function maybe_create( Conversation $conversation ): ?DispatchResult {
		if ( ! $this->conversations->try_begin_topic_creation( $conversation->id() ) ) {
			return null;
		}

		$envelope = new JobEnvelope(
			TopicCreationHandler::JOB_TYPE,
			array(
				'conversation_id' => $conversation->id(),
			),
			array(
				'conversation_id' => Classification::INTERNAL,
			)
		);

		return $this->dispatcher->enqueue( $envelope );
	}
}
