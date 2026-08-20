<?php
/**
 * Invalid idempotency key.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Events;

use Exception;

/**
 * Thrown when EventEnvelope is constructed with an idempotency key that is
 * not a non-empty string of 1-255 bytes. Caught only by EventEmitter, never
 * by the emitting caller (M02 plan §5.1).
 */
final class InvalidIdempotencyKeyException extends Exception {}
