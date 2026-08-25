<?php
/**
 * Delivers authorised transcript backfill pages to Telegram.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\SupportChatAdapter\Outbound;

/**
 * Implements Contract deliver_transcript_backfill. Support Chat exports
 * eligible plaintext pages; this adapter never invents transcript content.
 */
final class BackfillService {

	/**
	 * Constructor.
	 *
	 * @param DeliverMessageService $deliver Per-message deliver path.
	 */
	public function __construct( private readonly DeliverMessageService $deliver ) {}

	/**
	 * Accepts one backfill page.
	 *
	 * @param string                           $channel_case_ref Opaque binding UUID.
	 * @param array<int, array<string, mixed>> $messages         Ordered eligible messages.
	 *
	 * @return array{ok: bool, accepted: int, failed: int, reason: string|null}
	 */
	public function backfill( string $channel_case_ref, array $messages ): array {
		$accepted = 0;
		$failed   = 0;

		foreach ( $messages as $message ) {
			$key  = isset( $message['idempotency_key'] ) && is_string( $message['idempotency_key'] ) ? $message['idempotency_key'] : '';
			$body = isset( $message['body'] ) && is_string( $message['body'] ) ? $message['body'] : '';
			$attr = isset( $message['attribution'] ) && is_string( $message['attribution'] ) ? $message['attribution'] : 'Transcript';

			if ( '' === $key || '' === $body ) {
				++$failed;
				continue;
			}

			$result = $this->deliver->deliver( $channel_case_ref, $key, $body, $attr );
			if ( $result['ok'] ) {
				++$accepted;
			} else {
				++$failed;
			}
		}

		return array(
			'ok'       => 0 === $failed,
			'accepted' => $accepted,
			'failed'   => $failed,
			'reason'   => $failed > 0 ? 'partial_or_total_failure' : null,
		);
	}
}
