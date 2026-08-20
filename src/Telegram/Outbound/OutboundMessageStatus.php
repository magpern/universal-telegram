<?php
/**
 * Outbound message lifecycle status.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Telegram\Outbound;

/**
 * The lifecycle states of one row in universal_telegram_outbound_messages
 * (docs/adr/0014). dead_letter is a status, not a separate table.
 */
enum OutboundMessageStatus: string {
	case PENDING         = 'pending';
	case SENDING         = 'sending';
	case SENT            = 'sent';
	case RETRY_SCHEDULED = 'retry_scheduled';
	case DEAD_LETTER     = 'dead_letter';
	case PURGED          = 'purged';
}
