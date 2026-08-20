<?php
/**
 * Bot profile status.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Telegram\Configuration;

/**
 * The bot's own operational status, independent of its webhook
 * registration state (see BotProfile::webhook_registration_state()).
 */
enum BotStatus: string {
	case UNCONFIGURED = 'unconfigured';
	case ACTIVE       = 'active';
	case DISABLED     = 'disabled';
	case INVALID      = 'invalid';
}
