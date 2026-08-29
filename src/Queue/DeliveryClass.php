<?php
/**
 * Fixed outbound transport priority vocabulary (docs/adr/0045).
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Queue;

/**
 * The two — and only two — transport delivery classes. A plain, fixed
 * string persisted on `outbound_messages.delivery_class` and carried on the
 * job envelope; never message plaintext, never content-derived, never
 * user-supplied (docs/adr/0045 §1).
 */
final class DeliveryClass {

	/**
	 * Every existing caller: diagnostics, alerts, digests, admin Test
	 * Message, transcript backfill. Ordinary queue placement and cadence.
	 */
	public const STANDARD = 'standard';

	/**
	 * A Support Chat website-chat message (visitor message or Hub operator
	 * reply) delivered via Contract v1 `deliver_message` with an explicit
	 * `delivery_class = interactive_chat`. Placed ahead of `standard` work
	 * (docs/adr/0045 §3), FIFO within the class.
	 */
	public const INTERACTIVE_CHAT = 'interactive_chat';

	/**
	 * Not instantiable.
	 */
	private function __construct() {}

	/**
	 * Whether a value is one of the fixed classes.
	 *
	 * @param string $value Candidate class.
	 */
	public static function is_valid( string $value ): bool {
		return self::STANDARD === $value || self::INTERACTIVE_CHAT === $value;
	}

	/**
	 * Resolves an inbound wire value to a valid class, fail-closed: `null`
	 * (absent) becomes `standard`; any present value that is not a string
	 * in the fixed vocabulary returns `null` so the caller can reject it
	 * (docs/adr/0045 §2). Never guesses.
	 *
	 * @param mixed $value Raw request value (may be absent/`null`).
	 *
	 * @return string|null The resolved class, or `null` when the caller
	 *                      must reject the request.
	 */
	public static function from_wire( $value ): ?string {
		if ( null === $value ) {
			return self::STANDARD;
		}

		if ( is_string( $value ) && self::is_valid( $value ) ) {
			return $value;
		}

		return null;
	}

	/**
	 * Coerces a value read back from storage to a valid class, defaulting
	 * defensively to `standard` so a malformed persisted value can never
	 * poison queue handling (docs/adr/0045 §4, plan §4.1).
	 *
	 * @param mixed $value Stored value.
	 */
	public static function from_storage( $value ): string {
		return is_string( $value ) && self::is_valid( $value ) ? $value : self::STANDARD;
	}
}
