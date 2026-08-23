<?php
/**
 * AI provider failure classification.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\AI\Provider;

/**
 * The three outcomes AiFailureClassifier maps every failed AiResult onto,
 * mirroring Telegram\Client\FailureClassification's exact precedent
 * (docs/adr/0028 decision 5).
 */
enum AiFailureClassification {
	/**
	 * A definite, non-retryable rejection (4xx other than 401, including
	 * a content-policy refusal). Dead-letter immediately; no
	 * circuit-breaker impact.
	 */
	case TERMINAL;

	/**
	 * HTTP 401: invalid/revoked credential. Opens the 'ai_provider'
	 * circuit breaker indefinitely; no automatic half-open probe.
	 */
	case TOKEN_INVALID;

	/**
	 * Network error, timeout, HTTP 429, or HTTP 5xx. Rethrown to let
	 * WorkerRunner's generic retry sequence run; counts toward the
	 * circuit breaker and the shared attempt budget.
	 */
	case RETRYABLE;
}
