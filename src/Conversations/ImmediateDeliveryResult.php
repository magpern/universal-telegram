<?php
/**
 * Immediate/fallback delivery attempt result.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Conversations;

/**
 * The externally visible result of one ImmediateDeliveryAttempt::attempt()
 * call — deliberately narrower than the shared Queue\AttemptOutcome, since
 * every caller of this attempt only ever needs to know whether the visitor
 * can be told the message is delivered, or whether it remains pending
 * (M06.2 corrective plan v2 §3.2).
 */
enum ImmediateDeliveryResult {
	case DELIVERED;
	case PENDING;
}
