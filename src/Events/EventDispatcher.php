<?php
/**
 * Internal event ingestion orchestration.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Events;

/**
 * Called only by EventEmitter (§5.3.1), never directly by any emitter or
 * any hook subscriber. Performs, in order: (1) write the PUBLIC-only
 * history projection; (2) invoke rule evaluation (wired in WP6). Both
 * steps run synchronously, in-process, for the duration of the originating
 * request or job — the only asynchronous step in the entire pipeline
 * remains the Telegram HTTP call itself, already handled by M01's existing
 * queue (M02 plan §5.5).
 *
 * Not declared final: tests/integration/Events/EventEmitterTest.php
 * substitutes a throwing subclass to confirm EventEmitter::emit() never
 * lets a downstream exception propagate to its own caller.
 */
class EventDispatcher {

	/**
	 * Constructor.
	 *
	 * @param EventHistoryRepository $history_repository Writes the PUBLIC-only history projection.
	 */
	public function __construct( private readonly EventHistoryRepository $history_repository ) {}

	/**
	 * Performs the full ingestion sequence for one event occurrence.
	 *
	 * @param EventEnvelope $event The event to ingest.
	 */
	public function handle( EventEnvelope $event ): void {
		$this->history_repository->record( $event );

		// Rule evaluation is wired in WP6.
	}
}
