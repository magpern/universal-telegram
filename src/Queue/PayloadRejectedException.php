<?php
/**
 * Fail-closed payload classification failure.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Queue;

use Exception;

/**
 * Thrown immediately, at JobEnvelope construction, when a payload field is
 * unclassified or classified SENSITIVE or SECRET — a loud, catchable,
 * development-time failure a job-handler author must fix, never an
 * environmental failure to be silently logged.
 */
final class PayloadRejectedException extends Exception {}
