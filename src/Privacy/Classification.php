<?php
/**
 * Privacy classification levels.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Privacy;

/**
 * Four classification levels used throughout the plugin to decide what a
 * piece of data is allowed to become once it reaches a persisted or
 * logged surface.
 */
enum Classification: string {
	case PUBLIC    = 'public';
	case INTERNAL  = 'internal';
	case SENSITIVE = 'sensitive';
	case SECRET    = 'secret';
}
