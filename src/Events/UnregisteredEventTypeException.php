<?php
/**
 * Unregistered event type.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Events;

use Exception;

/**
 * Thrown when EventEnvelope is constructed for an event_type the Registry
 * does not know about. Caught only by EventEmitter, never by the emitting
 * caller (M02 plan §5.1).
 */
final class UnregisteredEventTypeException extends Exception {}
