<?php
/**
 * Conversation-to-Telegram outbound routing dispatch.
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
 * Enqueues the queue-before-topic routing job for a just-accepted visitor
 * message. The message row itself is already durably stored by
 * MessageRepository::create() before this is ever called — nothing is lost
 * while a topic is still 'pending' (M05 plan §5, docs/adr/0021).
 */
final class ConversationOutboundDispatcher {

	/**
	 * Constructor.
	 *
	 * @param Dispatcher $dispatcher M00's generic queue dispatcher, used as-is.
	 */
	public function __construct(
		private readonly Dispatcher $dispatcher
	) {}

	/**
	 * Enqueues the routing job for one already-stored visitor message.
	 *
	 * @param int $conversation_message_id The already-stored message's primary key.
	 * @param int $conversation_id         The owning conversation.
	 *
	 * @return DispatchResult
	 */
	public function route( int $conversation_message_id, int $conversation_id ): DispatchResult {
		$envelope = new JobEnvelope(
			ConversationOutboundHandler::JOB_TYPE,
			array(
				'conversation_message_id' => $conversation_message_id,
				'conversation_id'         => $conversation_id,
			),
			array(
				'conversation_message_id' => Classification::INTERNAL,
				'conversation_id'         => Classification::INTERNAL,
			)
		);

		return $this->dispatcher->enqueue( $envelope );
	}
}
