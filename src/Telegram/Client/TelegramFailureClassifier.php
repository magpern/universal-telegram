<?php
/**
 * Telegram failure classification.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Telegram\Client;

/**
 * Maps a failed TelegramApiResult onto one of four Telegram-specific
 * outcomes, entirely external to Queue\RetryPolicy (docs/adr/0014). Only
 * ever called for a result where ok() is false; a caller must not classify
 * a successful result.
 */
final class TelegramFailureClassifier {

	/**
	 * Classifies a failed API result.
	 *
	 * @param TelegramApiResult $result A result where ok() is false.
	 *
	 * @return FailureClassification
	 */
	public function classify( TelegramApiResult $result ): FailureClassification {
		if ( $result->is_network_error() ) {
			return FailureClassification::RETRYABLE;
		}

		$status = $result->http_status();

		if ( 429 === $status ) {
			return FailureClassification::RATE_LIMITED;
		}

		if ( 401 === $status ) {
			return FailureClassification::TOKEN_INVALID;
		}

		if ( 403 === $status || 400 === $status ) {
			return FailureClassification::TERMINAL;
		}

		if ( null !== $status && $status >= 500 ) {
			return FailureClassification::RETRYABLE;
		}

		// Anything else unrecognized (a status this classifier has no
		// specific rule for) is treated conservatively as retryable, never
		// silently dead-lettered.
		return FailureClassification::RETRYABLE;
	}
}
