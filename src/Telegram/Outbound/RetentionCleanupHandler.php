<?php
/**
 * Retention-based cleanup of message content and delivery-log rows.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Telegram\Outbound;

use UniversalTelegram\Migration\DeferredUpdateRepository;
use UniversalTelegram\Migration\QuiescenceGate;

/**
 * A recurring Action Scheduler action, independent of the queue's own
 * job-handler contract: nulls body_ciphertext for terminal (sent) messages
 * once telegram_message_retention_days has elapsed, then permanently
 * deletes the metadata row of any terminal (sent, dead_letter, or already
 * purged) message once telegram_delivery_log_retention_days has elapsed.
 * Also runs the ADR-0040 §3 deferred-update 30-day retention cleanup pass —
 * folded into this same daily handler, per that ADR's decision.
 */
final class RetentionCleanupHandler {

	public const HOOK = 'universal_telegram_retention_cleanup';

	private const DEFERRED_UPDATE_RETENTION_DAYS = 30;

	/**
	 * Constructor.
	 *
	 * @param OutboundMessageRepository     $messages                     Durable outbound message storage.
	 * @param int                           $message_retention_days       Days a sent message's own body is retained before being nulled.
	 * @param int                           $delivery_log_retention_days   Days a terminal message's own row is retained before being deleted entirely.
	 * @param QuiescenceGate|null           $quiescence                   Legacy-chat quiescence write-blocking gate (docs/adr/0040). Gates only the two message-retention passes above, never the deferred-update cleanup pass below.
	 * @param DeferredUpdateRepository|null $deferred_updates             Deletes replayed deferred-update rows older than 30 days — cleanup of already-replayed rows only, so this pass is never quiescence-gated and always runs.
	 */
	public function __construct(
		private readonly OutboundMessageRepository $messages,
		private readonly int $message_retention_days = 30,
		private readonly int $delivery_log_retention_days = 90,
		private readonly ?QuiescenceGate $quiescence = null,
		private readonly ?DeferredUpdateRepository $deferred_updates = null
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
		if ( null === $this->quiescence || $this->quiescence->is_idle() ) {
			foreach ( $this->messages->terminal_older_than( OutboundMessageStatus::SENT, $this->message_retention_days ) as $message ) {
				$this->messages->purge_body( $message->id() );
			}

			foreach ( $this->messages->terminal_older_than( null, $this->delivery_log_retention_days ) as $message ) {
				$this->messages->delete( $message->id() );
			}
		}

		if ( null !== $this->deferred_updates ) {
			$this->deferred_updates->delete_replayed_older_than( self::DEFERRED_UPDATE_RETENTION_DAYS );
		}
	}
}
