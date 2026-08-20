<?php
/**
 * Schema-degraded worker execution.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Queue;

use Exception;

/**
 * Synthesized by WorkerRunner when the plugin's schema is unavailable,
 * instead of invoking any job handler, and passed through exactly the
 * same failure-handling sequence used for any other worker exception —
 * never invoked to run and never marked complete by Action Scheduler.
 */
final class SchemaUnavailableException extends Exception {}
