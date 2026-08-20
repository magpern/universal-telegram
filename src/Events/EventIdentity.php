<?php
/**
 * Deterministic event identity.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Events;

/**
 * Event identity is derived, not generated (docs/adr/0015): re-emitting the
 * same logical occurrence with the same event_type, schema_version, and
 * idempotency_key always yields the same event_id, which is the entire
 * mechanism by which replay is recognized downstream (event history,
 * dispatch log).
 */
final class EventIdentity {

	/**
	 * Derives a deterministic event_id: the lower-case hex SHA-256 digest
	 * of event_type, schema_version, and idempotency_key, joined by the
	 * \x1f unit-separator byte to prevent concatenation collisions between
	 * differently-split inputs.
	 *
	 * @param string $event_type      The namespaced, dot-separated event type.
	 * @param int    $schema_version  The event type's schema version.
	 * @param string $idempotency_key The source-supplied idempotency key.
	 *
	 * @return string 64 lower-case hex characters.
	 */
	public static function derive( string $event_type, int $schema_version, string $idempotency_key ): string {
		return hash( 'sha256', $event_type . "\x1f" . $schema_version . "\x1f" . $idempotency_key );
	}
}
