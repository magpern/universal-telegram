<?php
/**
 * Malformed Telegram API response.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Telegram\Client;

use Exception;

/**
 * Thrown only when a response cannot be parsed into any recognizable shape
 * at all (not even a well-formed Telegram error body) — TelegramApiClient
 * never throws for an ordinary, well-formed error response; that becomes a
 * TelegramApiResult with ok = false, classified by TelegramFailureClassifier
 * like any other failure.
 */
final class TelegramApiException extends Exception {}
