<?php
/**
 * Idempotent Telegram forum-topic deletion dispatch.
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
 * The only caller that may enqueue a TopicDeletionHandler job. Owns the
 * compare-and-set into delete_pending, then enqueues (M07.1, docs/adr/0031).
 * Callers that find the conversation ineligible for remote delete must purge
 * locally instead of calling this class.
 */
final class TopicDeletionDispatcher {

	/**
	 * Constructor.
	 *
	 * @param ConversationRepository $conversations Owns the compare-and-set guard.
	 * @param Dispatcher             $dispatcher    Generic queue dispatcher.
	 */
	public function __construct(
		private readonly ConversationRepository $conversations,
		private readonly Dispatcher $dispatcher
	) {}

	/**
	 * Attempts to begin remote topic deletion. Only the CAS winner enqueues.
	 *
	 * @param Conversation $conversation An archived conversation that already passed eligibility.
	 *
	 * @return DispatchResult|null Null if this call did not win the guard.
	 */
	public function maybe_delete( Conversation $conversation ): ?DispatchResult {
		$claimed_lease_expires_at = $this->conversations->try_begin_topic_deletion( $conversation->id() );

		if ( null === $claimed_lease_expires_at ) {
			return null;
		}

		$envelope = new JobEnvelope(
			TopicDeletionHandler::JOB_TYPE,
			array(
				'conversation_id'          => $conversation->id(),
				'claimed_lease_expires_at' => $claimed_lease_expires_at,
			),
			array(
				'conversation_id'          => Classification::INTERNAL,
				'claimed_lease_expires_at' => Classification::INTERNAL,
			)
		);

		return $this->dispatcher->enqueue( $envelope );
	}
}
