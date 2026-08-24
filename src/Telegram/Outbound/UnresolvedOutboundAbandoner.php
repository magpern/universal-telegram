<?php
/**
 * Abandons outbound rows that can no longer be delivered.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Telegram\Outbound;

/**
 * Removes unresolved outbound messages whose bot or destination no longer
 * exists, firing the same terminal resolution hook conversation delivery
 * uses before deleting the row so the alert is not left stuck on stale
 * pending counts and dead-letter noise.
 */
final class UnresolvedOutboundAbandoner {

	/**
	 * Constructor.
	 *
	 * @param OutboundMessageRepository $messages Outbound message persistence.
	 */
	public function __construct(
		private readonly OutboundMessageRepository $messages
	) {}

	/**
	 * Abandons every unresolved outbound row for one destination.
	 *
	 * @param int $destination_id The destination being removed.
	 */
	public function abandon_for_destination( int $destination_id ): void {
		foreach ( $this->messages->unresolved_for_destination( $destination_id ) as $message ) {
			$this->abandon( $message, 'telegram_destination_removed' );
		}
	}

	/**
	 * Abandons every unresolved outbound row for one bot.
	 *
	 * @param int $bot_id The bot being removed.
	 */
	public function abandon_for_bot( int $bot_id ): void {
		foreach ( $this->messages->unresolved_for_bot( $bot_id ) as $message ) {
			$this->abandon( $message, 'telegram_destination_removed' );
		}
	}

	/**
	 * Fires the terminal resolution hook and deletes one unresolved row.
	 *
	 * @param OutboundMessage $message     The message to abandon.
	 * @param string          $reason_code A fixed stable code, never raw API text.
	 */
	public function abandon( OutboundMessage $message, string $reason_code ): void {
		/**
		 * Fires after an outbound message reaches a terminal Telegram outcome.
		 *
		 * @since 0.14.0
		 *
		 * @param string      $uuid         Outbound message uuid.
		 * @param string      $outcome      sent|failed.
		 * @param string|null $failure_code Fixed code on failure.
		 */
		do_action( 'universal_telegram_outbound_message_resolved', $message->message_uuid(), 'failed', $reason_code );

		$this->messages->delete( $message->id() );
	}
}
