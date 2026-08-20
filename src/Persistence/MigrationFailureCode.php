<?php
/**
 * Stable migration failure codes.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Persistence;

/**
 * Small, fixed, stable failure codes. Wherever a degraded-schema condition
 * is reported to any surface outside the plugin's own internal state, only
 * one of these values ever reaches it — the raw underlying database error
 * is never rendered to an administrator, never logged, never shown on any
 * diagnostic surface.
 */
enum MigrationFailureCode: string {
	case LOCK_UNAVAILABLE     = 'lock_unavailable';
	case STEP_FAILED          = 'step_failed';
	case POSTCONDITION_FAILED = 'postcondition_failed';
}
