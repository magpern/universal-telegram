<?php
/**
 * Inbound update type.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Telegram\Inbound;

/**
 * The small M01-supported set of Telegram update types, plus 'unsupported'
 * for anything outside it — still deduplicated and stored, never causing a
 * retry storm (docs/adr/0013).
 */
enum UpdateType: string {
	case MESSAGE             = 'message';
	case EDITED_MESSAGE      = 'edited_message';
	case CHANNEL_POST        = 'channel_post';
	case EDITED_CHANNEL_POST = 'edited_channel_post';
	case UNSUPPORTED         = 'unsupported';
}
