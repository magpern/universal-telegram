<?php
/**
 * AI provider failure classification.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\AI\Provider;

/**
 * Maps a failed AiResult onto one of three outcomes, mirroring
 * Telegram\Client\TelegramFailureClassifier's exact structure (docs/adr/0028
 * decision 5). Only ever called for a result where ok() is false.
 */
final class AiFailureClassifier {

	/**
	 * Classifies a failed completion result.
	 *
	 * @param AiResult $result A result where ok() is false.
	 *
	 * @return AiFailureClassification
	 */
	public function classify( AiResult $result ): AiFailureClassification {
		if ( $result->is_network_error() ) {
			return AiFailureClassification::RETRYABLE;
		}

		$status = $result->http_status();

		if ( 401 === $status ) {
			return AiFailureClassification::TOKEN_INVALID;
		}

		if ( 429 === $status ) {
			return AiFailureClassification::RETRYABLE;
		}

		if ( null !== $status && $status >= 500 ) {
			return AiFailureClassification::RETRYABLE;
		}

		if ( null !== $status && $status >= 400 ) {
			return AiFailureClassification::TERMINAL;
		}

		// Anything else unrecognized is treated conservatively as
		// retryable, never silently dead-lettered.
		return AiFailureClassification::RETRYABLE;
	}
}
