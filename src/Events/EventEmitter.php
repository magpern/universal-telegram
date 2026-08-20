<?php
/**
 * Safety-wrapped event emission façade.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Events;

use Throwable;
use UniversalTelegram\Audit\AuditLogger;
use UniversalTelegram\Privacy\Classification;

/**
 * The sole implementation backing universal_telegram_emit_event() — there
 * is no way to reach EventDispatcher::handle() except through this class.
 * Wraps the entire downstream call graph (envelope construction, history
 * write, rule evaluation) in one try/catch, reducing any failure anywhere
 * in that graph to one fixed, non-message-carrying diagnostic code. This is
 * the entire safety guarantee (docs/adr/0015, M02 plan §5.3.1). Constructed
 * once at composition-root init(), unconditionally.
 */
final class EventEmitter {

	private const EMISSION_FAILED_CODE = 'events.emission_failed';

	/**
	 * Constructor.
	 *
	 * @param Registry       $registry   The current request's event registry.
	 * @param EventDispatcher $dispatcher Internal ingestion orchestration.
	 * @param AuditLogger    $audit      Records the fixed failure code on any downstream failure.
	 */
	public function __construct(
		private readonly Registry $registry,
		private readonly EventDispatcher $dispatcher,
		private readonly AuditLogger $audit
	) {}

	/**
	 * Records one normalized event occurrence. Never throws.
	 *
	 * @param string               $event_type      The registered event type.
	 * @param array<string, mixed> $data            actor/subject/context/payload sub-arrays; a missing key defaults to [].
	 * @param string               $idempotency_key Source-supplied, mandatory, never generated internally.
	 */
	public function emit( string $event_type, array $data, string $idempotency_key ): void {
		try {
			$envelope = $this->build_envelope( $event_type, $data, $idempotency_key );
			$this->dispatcher->handle( $envelope );
		} catch ( Throwable $e ) {
			$this->audit->record( self::EMISSION_FAILED_CODE, 'system', null, array(), array(), Classification::INTERNAL );
		}
	}

	/**
	 * Builds the envelope from the caller-supplied data shape.
	 *
	 * @param string               $event_type      The registered event type.
	 * @param array<string, mixed> $data            actor/subject/context/payload sub-arrays.
	 * @param string               $idempotency_key Source-supplied idempotency key.
	 *
	 * @return EventEnvelope
	 *
	 * @throws UnregisteredEventTypeException If $event_type is not registered.
	 * @throws InvalidIdempotencyKeyException If $idempotency_key is not 1-255 bytes.
	 * @throws UnclassifiedFieldException     If any field has no classification-map entry.
	 */
	private function build_envelope( string $event_type, array $data, string $idempotency_key ): EventEnvelope {
		$actor   = isset( $data['actor'] ) && is_array( $data['actor'] ) ? $data['actor'] : array();
		$subject = isset( $data['subject'] ) && is_array( $data['subject'] ) ? $data['subject'] : array();
		$context = isset( $data['context'] ) && is_array( $data['context'] ) ? $data['context'] : array();
		$payload = isset( $data['payload'] ) && is_array( $data['payload'] ) ? $data['payload'] : array();

		return new EventEnvelope( $this->registry, $event_type, $idempotency_key, EventSource::WORDPRESS_CORE, $actor, $subject, $context, $payload );
	}
}
