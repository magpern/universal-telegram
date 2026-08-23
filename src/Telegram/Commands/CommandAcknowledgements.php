<?php
/**
 * Fixed, non-sensitive Telegram acknowledgement text for bot commands.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Telegram\Commands;

/**
 * Every string an administrative-bot command may ever send back to
 * Telegram (M08, ADR-0027) is one of a small, fixed set — never built from
 * a template that could interpolate sensitive data by mistake. Purely
 * static: no instance, no injected state.
 */
final class CommandAcknowledgements {

	public const WRONG_CONTEXT = "This command isn't available here.";

	public const MALFORMED = 'Unrecognized command syntax. Send /help for the command list.';

	public const WOOCOMMERCE_INACTIVE = 'WooCommerce is not active on this site.';

	public const NOT_FOUND = 'Not found or unavailable.';

	public const TOO_MANY_ORDERS = 'Too many matching orders — use the Hub.';

	public const NO_PENDING_CONFIRMATION = 'No pending confirmation, or it expired — resend the original command.';
}
