<?php
/**
 * Delivers Support Chat messages to an escalated Telegram case.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\SupportChatAdapter\Outbound;

use UniversalTelegram\Privacy\Classification;
use UniversalTelegram\Queue\DeliveryClass;
use UniversalTelegram\Queue\Dispatcher;
use UniversalTelegram\Queue\DispatchState;
use UniversalTelegram\Queue\ExpeditedDispatchTrigger;
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
	 * @param ChannelBindingRepository      $bindings      Binding storage.
	 * @param DeliveryIdempotencyRepository $delivery_keys Accept dedupe.
	 * @param OutboundMessageRepository     $messages      Encrypted outbound store.
	 * @param Dispatcher                    $dispatcher    Queue dispatcher.
	 * @param ExpeditedDispatchTrigger|null $expedited     Optional ADR-0023 non-blocking queue-runner nudge, fired only after a successful `interactive_chat` enqueue (docs/adr/0045 §4).
	 */
	public function __construct(
		private readonly ChannelBindingRepository $bindings,
		private readonly DeliveryIdempotencyRepository $delivery_keys,
		private readonly OutboundMessageRepository $messages,
		private readonly Dispatcher $dispatcher,
		private readonly ?ExpeditedDispatchTrigger $expedited = null
	) {}

	/**
	 * Accepts a message for delivery.
	 *
	 * @param string $channel_case_ref Support Chat conversation UUID (docs/adr/0043) — resolved to the local binding here; never the UT-local binding UUID.
	 * @param string $idempotency_key  Contract idempotency key.
	 * @param string $plaintext_body   Message body (in memory only).
	 * @param string $attribution      Channel-facing attribution label.
	 * @param string $delivery_class   Fixed transport priority class (docs/adr/0045). Already validated by the caller; defaults to `standard`.
	 *
	 * @return array{ok: bool, reused: bool, reason: string|null}
	 */
	public function deliver(
		string $channel_case_ref,
		string $idempotency_key,
		string $plaintext_body,
		string $attribution = '',
		string $delivery_class = DeliveryClass::STANDARD
	): array {
		$delivery_class = DeliveryClass::from_storage( $delivery_class );
		$existing       = $this->delivery_keys->find( $idempotency_key );
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

		$message = $this->messages->create( $binding->bot_id(), $binding->destination_id(), $text, null, $delivery_class );
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
				'delivery_class' => $delivery_class,
			),
			array(
				'message_uuid'   => Classification::INTERNAL,
				'bot_id'         => Classification::INTERNAL,
				'destination_id' => Classification::INTERNAL,
				'delivery_class' => Classification::INTERNAL,
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

		if ( DeliveryClass::INTERACTIVE_CHAT === $delivery_class && null !== $this->expedited ) {
			// ADR-0023 / docs/adr/0045 §4: a non-blocking, never-throwing
			// nudge to Action Scheduler's own async runner so an interactive
			// website-chat send starts promptly. The durable action is
			// already enqueued; this proves nothing and blocks nothing.
			$this->expedited->trigger();
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
