<?php
/**
 * Retention-based cleanup of message content and delivery-log rows.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Telegram\Outbound;


/**
 * A recurring Action Scheduler action, independent of the queue's own
 * job-handler contract: nulls body_ciphertext for terminal (sent) messages
 * once telegram_message_retention_days has elapsed, then permanently
 * deletes the metadata row of any terminal (sent, dead_letter, or already
 * purged) message once telegram_delivery_log_retention_days has elapsed.
 * 
 * folded into this same daily handler, per that ADR's decision.
 */
final class RetentionCleanupHandler {

	public const HOOK = 'universal_telegram_retention_cleanup';


	/**
	 * Constructor.
	 *
	 * @param OutboundMessageRepository     $messages                     Durable outbound message storage.
	 * @param int                           $message_retention_days       Days a sent message's own body is retained before being nulled.
	 * @param int                           $delivery_log_retention_days   Days a terminal message's own row is retained before being deleted entirely.
	 */
	public function __construct(
		private readonly OutboundMessageRepository $messages,
		private readonly int $message_retention_days = 30,
		private readonly int $delivery_log_retention_days = 90
	) {}

	/**
	 * Runs one cleanup pass. The two message-retention passes skip the
	 * entire cycle outside `idle` (docs/adr/0040 §5) — never marked failed.
	 * The deferred-update 30-day cleanup pass is unconditional: it only
	 * ever touches rows already stamped `replayed_at`, never a live
	 * legacy-chat writer, so it must keep running in every state
	 * (docs/adr/0040 §3, explicitly excluded from every drain query).
	 */
	public function run(): void {
		foreach ( $this->messages->terminal_older_than( OutboundMessageStatus::SENT, $this->message_retention_days ) as $message ) {
			$this->messages->purge_body( $message->id() );
		}

		foreach ( $this->messages->terminal_older_than( null, $this->delivery_log_retention_days ) as $message ) {
			$this->messages->delete( $message->id() );
		}
	}
}
