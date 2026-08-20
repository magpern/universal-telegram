<?php
/**
 * Invalid destination construction.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Telegram\Configuration;

use Exception;

/**
 * Thrown when a Destination is constructed with a message_thread_id on any
 * kind other than 'supergroup'.
 */
final class InvalidDestinationException extends Exception {}
