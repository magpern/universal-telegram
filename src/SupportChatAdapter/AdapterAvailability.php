<?php
/**
 * Adapter availability states.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\SupportChatAdapter;

/**
 * Channel-only availability for the Support Chat adapter. Non-chat Telegram
 * features are unaffected by these states.
 */
enum AdapterAvailability: string {
	case Disabled    = 'disabled';
	case Unavailable = 'unavailable';
	case Compatible  = 'compatible';
}
