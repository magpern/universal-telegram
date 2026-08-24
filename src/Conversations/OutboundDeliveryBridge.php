<?php
/**
 * Bridges outbound Telegram send outcomes to conversation delivery state.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Conversations;

use UniversalTelegram\Telegram\Client\TelegramTopicError;
use UniversalTelegram\Telegram\Configuration\DestinationRepository;
use UniversalTelegram\Telegram\Outbound\OutboundMessageRepository;

/**
 * Subscribes to universal_telegram_outbound_message_resolved. Telegram
 * Outbound never imports Conversations (M07.1, docs/adr/0031).
 */
final class OutboundDeliveryBridge {

	public const HOOK = 'universal_telegram_outbound_message_resolved';

	/**
	 * Constructor.
	 *
	 * @param MessageRepository         $messages      Conversation messages.
	 * @param ConversationRepository    $conversations Conversations.
	 * @param OutboundMessageRepository $outbound      Outbound rows (for destination id).
	 * @param DestinationRepository     $destinations  Destination rows.
	 */
	public function __construct(
		private readonly MessageRepository $messages,
		private readonly ConversationRepository $conversations,
		private readonly OutboundMessageRepository $outbound,
		private readonly DestinationRepository $destinations
	) {}

	/**
	 * Registers the action listener.
	 */
	public function register(): void {
		add_action( self::HOOK, array( $this, 'on_resolved' ), 10, 3 );
	}

	/**
	 * Applies delivery outcome to the matching conversation message.
	 *
	 * @param string      $outbound_uuid Outbound message uuid.
	 * @param string      $outcome       sent|failed.
	 * @param string|null $failure_code  Fixed code on failure, else null.
	 */
	public function on_resolved( string $outbound_uuid, string $outcome, ?string $failure_code = null ): void {
		$message = $this->messages->find_by_outbound_uuid( $outbound_uuid );

		if ( null === $message ) {
			return;
		}

		if ( 'sent' === $outcome ) {
			$this->messages->mark_delivery_sent( $message->id() );
			return;
		}

		if ( 'failed' !== $outcome ) {
			return;
		}

		$this->messages->mark_delivery_failed( $message->id() );

		if ( null === $failure_code ) {
			return;
		}

		if ( ! in_array( $failure_code, array( TelegramTopicError::TOPIC_NOT_FOUND, TelegramTopicError::TOPIC_CLOSED ), true ) ) {
			return;
		}

		$conversation = $this->conversations->find( $message->conversation_id() );

		if ( null === $conversation || null === $conversation->destination_id() ) {
			return;
		}

		$outbound = $this->outbound->find_by_uuid( $outbound_uuid );

		if ( null === $outbound ) {
			return;
		}

		$destination = $this->destinations->find( $outbound->destination_id() );
		$owned       = $this->destinations->find( $conversation->destination_id() );

		if ( null === $destination || null === $owned ) {
			return;
		}

		if ( $destination->id() !== $owned->id()
			|| $destination->bot_id() !== $conversation->bot_id()
			|| $destination->chat_id() !== $owned->chat_id()
			|| $destination->message_thread_id() !== $conversation->telegram_topic_id()
		) {
			return;
		}

		$this->conversations->mark_topic_lifecycle(
			$conversation->id(),
			TopicLifecycleState::UNAVAILABLE,
			$failure_code
		);
	}
}
