<?php
/**
 * Outbound message send handler.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Telegram\Outbound;

use RuntimeException;
use UniversalTelegram\Core\Security\CredentialState;
use UniversalTelegram\Telegram\Client\TelegramApiClient;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;
use UniversalTelegram\Telegram\Configuration\DestinationRepository;

/**
 * The queue's registered handler for MessageDispatcher::JOB_TYPE. Re-reads
 * and decrypts the message row at execution time — the JobEnvelope payload
 * never carries text or a token (docs/adr/0012). At WP6 this handler
 * implements only the success path; WP8 wires in Telegram-specific
 * reliability (rate limiting, circuit breaking, dead-letter) on top of it,
 * replacing the plain rethrow below with classified, non-throwing handling
 * where appropriate.
 */
class SendMessageHandler {

	/**
	 * Constructor.
	 *
	 * @param OutboundMessageRepository $messages     Durable, encrypted message storage.
	 * @param BotProfileRepository      $bots         Bot profiles, including token decryption.
	 * @param DestinationRepository     $destinations Destinations.
	 * @param TelegramApiClient         $client       The Telegram Bot API client.
	 */
	public function __construct(
		private readonly OutboundMessageRepository $messages,
		private readonly BotProfileRepository $bots,
		private readonly DestinationRepository $destinations,
		private readonly TelegramApiClient $client
	) {}

	/**
	 * The Action Scheduler job handler.
	 *
	 * @param array{job_id: string, job_type: string, attempt: int, payload: array<string, mixed>} $job The job.
	 *
	 * @throws RuntimeException On any failure to send — at WP6, every
	 *                           failure is generically retryable; WP8
	 *                           replaces this with classified handling.
	 */
	public function handle_job( array $job ): void {
		$message_uuid   = (string) $job['payload']['message_uuid'];
		$bot_id         = (int) $job['payload']['bot_id'];
		$destination_id = (int) $job['payload']['destination_id'];

		$message = $this->messages->find_by_uuid( $message_uuid );

		if ( null === $message ) {
			throw new RuntimeException( 'telegram_outbound_message_not_found' );
		}

		$bot         = $this->bots->find( $bot_id );
		$destination = $this->destinations->find( $destination_id );

		if ( null === $bot || null === $destination ) {
			throw new RuntimeException( 'telegram_send_missing_bot_or_destination' );
		}

		$token_result = $this->bots->decrypt_token( $bot );

		if ( CredentialState::AVAILABLE !== $token_result->state() || null === $token_result->plaintext() ) {
			throw new RuntimeException( 'telegram_send_token_unavailable' );
		}

		$body_result = $this->messages->decrypt_body( $message );

		if ( null === $body_result || CredentialState::AVAILABLE !== $body_result->state() || null === $body_result->plaintext() ) {
			throw new RuntimeException( 'telegram_send_body_unavailable' );
		}

		$this->messages->mark_sending( $message->id() );

		$result = $this->client->send_message(
			$token_result->plaintext(),
			$destination->chat_id(),
			$body_result->plaintext(),
			$destination->message_thread_id(),
			$message->parse_mode()
		);

		if ( ! $result->ok() ) {
			throw new RuntimeException( 'telegram_send_failed' );
		}

		$telegram_message_id = isset( $result->result()['message_id'] ) && is_int( $result->result()['message_id'] )
			? $result->result()['message_id']
			: null;

		$this->messages->mark_sent( $message->id(), $telegram_message_id );
	}
}
