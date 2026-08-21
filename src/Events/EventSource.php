<?php
/**
 * Event source enum.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Events;

/**
 * WORDPRESS_CORE is the only value M02 itself emits. WOOCOMMERCE, VISITOR,
 * and CUSTOM are reserved future values, declared now so M03/M04/
 * third-party code has a stable value to target without M02 needing to
 * build their emitters (M02 plan §5.1).
 */
enum EventSource: string {
	case WORDPRESS_CORE = 'wordpress_core';
	case WOOCOMMERCE    = 'woocommerce';
	case VISITOR        = 'visitor';
	case CUSTOM         = 'custom';
}
