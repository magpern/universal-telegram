<?php
/**
 * Delivers Support Chat messages to an escalated Telegram case.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\SupportChatAdapter\Outbound;

use UniversalTelegram\Privacy\Classification;
use UniversalTelegram\Queue\Dispatcher;
use UniversalTelegram\Queue\DispatchState;
use UniversalTelegram\Queue\JobEnvelope;
use UniversalTelegram\SupportChatAdapter\ChannelBindingRepository;
use UniversalTelegram\SupportChatAdapter\DeliveryIdempotencyRepository;
use UniversalTelegram\Telegram\Outbound\MessageDispatcher;
use UniversalTelegram\Telegram\Outbound\OutboundMessageRepository;

/**
 * Implements Contract deliver_message and per-message backfill sends.
 * Plaintext is accepted in memory and encrypted by the outbound queue.
 */
final class DeliverMessageService {

	/**
	 * Constructor.
	 *
	 * @param ChannelBindingRepository      $bindings     Binding storage.
	 * @param DeliveryIdempotencyRepository $delivery_keys Accept dedupe.
	 * @param OutboundMessageRepository     $messages     Encrypted outbound store.
	 * @param Dispatcher                    $dispatcher   Queue dispatcher.
	 */
	public function __construct(
		private readonly ChannelBindingRepository $bindings,
		private readonly DeliveryIdempotencyRepository $delivery_keys,
		private readonly OutboundMessageRepository $messages,
		private readonly Dispatcher $dispatcher
	) {}

	/**
	 * Accepts a message for delivery.
	 *
	 * @param string $channel_case_ref Support Chat conversation UUID (docs/adr/0043) — resolved to the local binding here; never the UT-local binding UUID.
	 * @param string $idempotency_key  Contract idempotency key.
	 * @param string $plaintext_body   Message body (in memory only).
	 * @param string $attribution      Channel-facing attribution label.
	 *
	 * @return array{ok: bool, reused: bool, reason: string|null}
	 */
	public function deliver(
		string $channel_case_ref,
		string $idempotency_key,
		string $plaintext_body,
		string $attribution = ''
	): array {
		$existing = $this->delivery_keys->find( $idempotency_key );
		if ( null !== $existing ) {
			return array(
				'ok'     => true,
				'reused' => true,
				'reason' => null,
			);
		}

		$binding = $this->bindings->find_by_conversation_uuid( $channel_case_ref );
		if ( null === $binding || ! $binding->is_active() ) {
			return array(
				'ok'     => false,
				'reused' => false,
				'reason' => 'binding_unavailable',
			);
		}

		$text = '' === $attribution
			? $plaintext_body
			: '[' . $attribution . "]\n" . $plaintext_body;

		$message = $this->messages->create( $binding->bot_id(), $binding->destination_id(), $text, null );
		if ( null === $message ) {
			return array(
				'ok'     => false,
				'reused' => false,
				'reason' => 'enqueue_failed',
			);
		}

		$envelope = new JobEnvelope(
			MessageDispatcher::JOB_TYPE,
			array(
				'message_uuid'   => $message->message_uuid(),
				'bot_id'         => $binding->bot_id(),
				'destination_id' => $binding->destination_id(),
			),
			array(
				'message_uuid'   => Classification::INTERNAL,
				'bot_id'         => Classification::INTERNAL,
				'destination_id' => Classification::INTERNAL,
			)
		);

		$result = $this->dispatcher->enqueue( $envelope );
		if ( DispatchState::SCHEDULED !== $result->state() ) {
			return array(
				'ok'     => false,
				'reused' => false,
				'reason' => 'enqueue_failed',
			);
		}

		$this->delivery_keys->record( $idempotency_key, $binding->binding_uuid(), $message->message_uuid() );
		$this->bindings->record_delivered_key( $binding->binding_uuid(), $idempotency_key, $binding->cas_version() );

		return array(
			'ok'     => true,
			'reused' => false,
			'reason' => null,
		);
	}
}
