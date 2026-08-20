<?php
/**
 * Destination kind.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Telegram\Configuration;

/**
 * The four Telegram chat kinds a destination may target. Only 'supergroup'
 * may carry a forum-topic message_thread_id (docs/adr — plan §6, §8).
 */
enum DestinationKind: string {
	case PRIVATE    = 'private';
	case GROUP      = 'group';
	case SUPERGROUP = 'supergroup';
	case CHANNEL    = 'channel';
}
