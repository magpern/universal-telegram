<?php
/**
 * Webhook registration/rotation outcome.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Telegram\Configuration;

/**
 * The outcome of one WebhookRegistrationCoordinator operation.
 */
enum RegistrationOutcome {
	/**
	 * A clean, confirmed Telegram success.
	 */
	case SUCCESS;

	/**
	 * A clean, definite Telegram rejection.
	 */
	case REJECTED;

	/**
	 * A network-transport failure or unparseable response — the outcome
	 * genuinely cannot be determined.
	 */
	case UNCERTAIN;

	/**
	 * The operation was not valid for the bot's current state (e.g.
	 * rotate() while a rotation is already in progress), or the bot's
	 * token/secret could not be decrypted. No attempt was made.
	 */
	case INVALID_STATE;
}
