<?php
/**
 * Telegram failure classification.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Telegram\Client;

/**
 * The four Telegram-specific outcomes TelegramFailureClassifier maps every
 * failed TelegramApiResult onto, external to Queue\RetryPolicy entirely
 * (docs/adr/0014).
 */
enum FailureClassification {
	/**
	 * HTTP 429. Defer by rescheduling directly, honoring retry_after.
	 * Never counts toward RetryPolicy's budget; never affects a circuit
	 * breaker.
	 */
	case RATE_LIMITED;

	/**
	 * A definite, non-retryable rejection (e.g. chat not found, bot
	 * blocked/kicked, invalid forum topic). Dead-letter immediately; no
	 * circuit-breaker impact.
	 */
	case TERMINAL;

	/**
	 * HTTP 401. Opens the bot-scope circuit breaker indefinitely; no
	 * automatic half-open probe.
	 */
	case TOKEN_INVALID;

	/**
	 * Network error, timeout, or HTTP 5xx. Rethrown to let WorkerRunner's
	 * generic retry sequence run; counts toward both circuit breakers.
	 */
	case RETRYABLE;
}
