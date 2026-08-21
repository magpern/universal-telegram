<?php
/**
 * Deterministic per-event sampling.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Events\Visitor;

/**
 * Admits an event if sha256(event_uuid)'s first 4 hex characters, read as
 * an integer mod 100, fall under the configured sampling percentage
 * (M04 plan §4.4). `session_started` and `javascript_error` are exempt —
 * always admitted — since they are the lowest-volume, highest-diagnostic-
 * value types.
 */
final class Sampler {

	private const EXEMPT_EVENT_TYPES = array(
		VisitorEventCatalog::SESSION_STARTED,
		VisitorEventCatalog::JAVASCRIPT_ERROR,
	);

	/**
	 * Whether the event is admitted under the configured sampling rate.
	 *
	 * @param string $event_type        The full, registered event type.
	 * @param string $event_uuid        The client-supplied event UUID.
	 * @param int    $sampling_percent  1-100.
	 *
	 * @return bool
	 */
	public function admits( string $event_type, string $event_uuid, int $sampling_percent ): bool {
		if ( in_array( $event_type, self::EXEMPT_EVENT_TYPES, true ) ) {
			return true;
		}

		$bucket = hexdec( substr( hash( 'sha256', $event_uuid ), 0, 4 ) ) % 100;

		return $bucket < $sampling_percent;
	}
}
