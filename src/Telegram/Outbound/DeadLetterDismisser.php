<?php
/**
 * Operator dismissal of dead-lettered outbound messages.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Telegram\Outbound;

use UniversalTelegram\Audit\AuditLogger;
use UniversalTelegram\Privacy\Classification;
use UniversalTelegram\Telegram\Reliability\QueueHealthAlert;

/**
 * Removes a dead-lettered outbound row after operator review. The delivery
 * outcome hook already fired when the message was dead-lettered; dismissal
 * only clears the retained row so the queue-health alert can recover.
 */
final class DeadLetterDismisser {

	/**
	 * Constructor.
	 *
	 * @param OutboundMessageRepository $messages     Outbound message persistence.
	 * @param AuditLogger               $audit_logger Operator-action audit trail.
	 */
	public function __construct(
		private readonly OutboundMessageRepository $messages,
		private readonly AuditLogger $audit_logger
	) {}

	/**
	 * Permanently removes one dead-lettered message row.
	 *
	 * @param int $id The message's primary key.
	 *
	 * @return bool
	 */
	public function dismiss( int $id ): bool {
		$message = $this->messages->find( $id );

		if ( null === $message || OutboundMessageStatus::DEAD_LETTER !== $message->status() ) {
			return false;
		}

		if ( ! $this->messages->delete( $id ) ) {
			return false;
		}

		$this->audit_logger->record(
			'telegram_dead_letter_dismissed',
			'user',
			get_current_user_id(),
			array(
				'message_id'     => $message->id(),
				'bot_id'         => $message->bot_id(),
				'destination_id' => $message->destination_id(),
				'reason_code'    => (string) $message->last_failure_code(),
			),
			array(
				'message_id'     => Classification::INTERNAL,
				'bot_id'         => Classification::INTERNAL,
				'destination_id' => Classification::INTERNAL,
				'reason_code'    => Classification::INTERNAL,
			),
			Classification::INTERNAL
		);

		QueueHealthAlert::bust_alert_cache();

		return true;
	}
}
