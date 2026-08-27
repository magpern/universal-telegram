<?php
/**
 * Notifies operators on an escalated Telegram case.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\SupportChatAdapter\Outbound;

/**
 * Implements Contract notify_operators as a bounded non-secret summary
 * delivery through DeliverMessageService.
 */
final class NotifyOperatorsService {

	/**
	 * Constructor.
	 *
	 * @param DeliverMessageService $deliver Deliver path (reused for notify text).
	 */
	public function __construct( private readonly DeliverMessageService $deliver ) {}

	/**
	 * Sends a notification summary to the bound topic.
	 *
	 * @param string $channel_case_ref Support Chat conversation UUID (docs/adr/0043).
	 * @param string $idempotency_key  Contract idempotency key.
	 * @param string $kind             Notification kind.
	 * @param string $summary          Bounded non-secret summary.
	 *
	 * @return array{ok: bool, reused: bool, reason: string|null}
	 */
	public function notify(
		string $channel_case_ref,
		string $idempotency_key,
		string $kind,
		string $summary
	): array {
		$body = trim( $kind ) . ( '' === trim( $summary ) ? '' : ': ' . trim( $summary ) );

		return $this->deliver->deliver( $channel_case_ref, $idempotency_key, $body, 'Notification' );
	}
}
