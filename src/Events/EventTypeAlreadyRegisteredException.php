<?php
/**
 * Duplicate event-type registration.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Events;

use Exception;

/**
 * Thrown by Registry::register() when the same (event_type, schema_version)
 * pair is registered more than once in the same request (M02 plan §5.2).
 */
final class EventTypeAlreadyRegisteredException extends Exception {}
