<?php
/**
 * Visitor-to-Telegram outbound routing handler.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Conversations;

use RuntimeException;
use UniversalTelegram\Privacy\Classification;
use UniversalTelegram\Queue\Dispatcher;
use UniversalTelegram\Queue\JobEnvelope;
use UniversalTelegram\Queue\WorkerRunner;
use UniversalTelegram\Telegram\Outbound\MessageDispatcher;
use UniversalTelegram\Telegram\Outbound\OutboundMessage;
use UniversalTelegram\Telegram\Outbound\OutboundMessageRepository;

/**
 * The queue's registered handler for
 * ConversationOutboundDispatcher::route()'s job. Queue-before-topic: the
 * message row already exists (created before this job was even enqueued),
 * so nothing is ever lost while a topic is still 'pending'. Re-checks the
 * conversation's own topic_creation_state on every run, self-rescheduling
 * (outside Queue\RetryPolicy's counted attempt budget, exactly like
 * Telegram\Outbound\SendMessageHandler::defer_locally()) until the topic is
 * 'created' or a bounded maximum wait elapses, at which point the message
 * is marked delivery_state='failed' but remains stored and visible — never
 * silently dropped (M05 plan §5, docs/adr/0021).
 */
class ConversationOutboundHandler {

	public const JOB_TYPE = 'conversation_route_outbound';

	private const MAX_WAIT_SECONDS   = 300;
	private const POLL_DELAY_SECONDS = 5;

	/**
	 * Constructor.
	 *
	 * @param MessageRepository         $messages          Conversation message persistence and decryption.
	 * @param ConversationRepository    $conversations      Conversation persistence.
	 * @param OutboundMessageRepository $outbound_messages Existing, unmodified outbound message storage.
	 * @param Dispatcher                $dispatcher         M00's generic queue dispatcher, used as-is.
	 */
	public function __construct(
		private readonly MessageRepository $messages,
		private readonly ConversationRepository $conversations,
		private readonly OutboundMessageRepository $outbound_messages,
		private readonly Dispatcher $dispatcher
	) {}

	/**
	 * The Action Scheduler job handler.
	 *
	 * @param array{job_id: string, job_type: string, attempt: int, payload: array<string, mixed>} $job The job.
	 *
	 * @throws RuntimeException Only on a transient storage failure, so WorkerRunner's own generic retry sequence runs.
	 */
	public function handle_job( array $job ): void {
		$message_id      = (int) $job['payload']['conversation_message_id'];
		$conversation_id = (int) $job['payload']['conversation_id'];

		$message = $this->messages->find( $message_id );

		if ( null === $message || 'stored' !== $message->delivery_state() ) {
			// Already resolved (sent/failed) or gone — nothing to do.
			return;
		}

		$conversation = $this->conversations->find( $conversation_id );

		if ( null === $conversation ) {
			return;
		}

		if ( 'created' === $conversation->topic_creation_state() && null !== $conversation->destination_id() ) {
			$this->create_and_route( $message, $conversation );
			return;
		}

		if ( 'failed' === $conversation->topic_creation_state() || $this->wait_exceeded( $message ) ) {
			$this->messages->mark_delivery_failed( $message->id() );
			return;
		}

		$this->reschedule( $job );
	}

	/**
	 * Hands the message off to the existing, unmodified outbound pipeline:
	 * decrypts, durably stores the outbound row, enqueues the durable send
	 * job, and marks the visitor message routed. Public so the in-process
	 * immediate-delivery attempt (M06.2 corrective plan v2 §3.2, ADR-0023
	 * amendment) can reuse the exact same store-then-enqueue sequence
	 * before immediately attempting `SendMessageHandler::try_once()` on the
	 * row it just created — the durable job and row always exist first, so
	 * a bounded synchronous attempt that doesn't deliver never loses the
	 * message.
	 *
	 * @param ConversationMessage $message      The visitor message to route.
	 * @param Conversation        $conversation The owning conversation, with its topic already created.
	 *
	 * @return OutboundMessage|null The newly created outbound row, or null if the message's own body could not be decrypted (already marked delivery_state='failed').
	 *
	 * @throws RuntimeException On a transient storage failure, so WorkerRunner's own generic retry sequence runs.
	 */
	public function create_and_route( ConversationMessage $message, Conversation $conversation ): ?OutboundMessage {
		$plaintext = $this->messages->decrypt( $message );

		if ( null === $plaintext ) {
			$this->messages->mark_delivery_failed( $message->id() );
			return null;
		}

		// One-time Telegram context header (M06.3, ADR-0024): added only to
		// the conversation's first accepted message, identified
		// deterministically by insertion sequence — never a request-scoped
		// flag, so a retried or fallback-routed first message is still
		// correctly recognized. Carries only the display name and the
		// short, non-secret reference; never the bearer secret, the
		// internal numeric conversation id, or raw ciphertext.
		if ( $this->messages->is_first_message( $message ) ) {
			$display_name = $this->conversations->decrypt_display_name( $conversation );

			if ( null !== $display_name && '' !== $display_name ) {
				$plaintext = ConversationDisplay::first_message_context_header( $display_name, $conversation->conversation_uuid() ) . "\n" . $plaintext;
			}
		}

		$outbound = $this->outbound_messages->create( $conversation->bot_id(), (int) $conversation->destination_id(), $plaintext, null );

		if ( null === $outbound ) {
			throw new RuntimeException( 'conversation_outbound_store_failed' );
		}

		$envelope = new JobEnvelope(
			MessageDispatcher::JOB_TYPE,
			array(
				'message_uuid'   => $outbound->message_uuid(),
				'bot_id'         => $conversation->bot_id(),
				'destination_id' => $conversation->destination_id(),
			),
			array(
				'message_uuid'   => Classification::INTERNAL,
				'bot_id'         => Classification::INTERNAL,
				'destination_id' => Classification::INTERNAL,
			)
		);

		$this->dispatcher->enqueue( $envelope );

		$this->messages->mark_routed( $message->id(), $outbound->message_uuid() );

		return $outbound;
	}

	/**
	 * Whether the bounded maximum wait has elapsed since the message was
	 * first stored.
	 *
	 * @param ConversationMessage $message The message.
	 *
	 * @return bool
	 */
	private function wait_exceeded( ConversationMessage $message ): bool {
		$created_at = strtotime( $message->created_at() . ' UTC' );

		return false !== $created_at && ( time() - $created_at ) > self::MAX_WAIT_SECONDS;
	}

	/**
	 * Non-throwing, budget-preserving reschedule: re-runs the same job a
	 * short delay later via Action Scheduler's own public scheduling
	 * function, mirroring SendMessageHandler::defer_locally().
	 *
	 * @param array{job_id: string, job_type: string, attempt: int, payload: array<string, mixed>} $job The job to reschedule.
	 */
	private function reschedule( array $job ): void {
		$args = array(
			array(
				'job_id'   => $job['job_id'],
				'job_type' => self::JOB_TYPE,
				'attempt'  => 1,
				'payload'  => $job['payload'],
			),
		);

		as_schedule_single_action( time() + self::POLL_DELAY_SECONDS, WorkerRunner::HOOK, $args, WorkerRunner::GROUP );
	}
}
