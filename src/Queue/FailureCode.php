<?php
/**
 * Stable queue failure codes.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Queue;

/**
 * Small, fixed, stable failure codes. Never a raw exception message.
 */
enum FailureCode: string {
	case DISPATCH_EXCEPTION           = 'dispatch_exception';
	case DISPATCH_INVALID_ACTION_ID   = 'dispatch_invalid_action_id';
	case RESCHEDULE_EXCEPTION         = 'reschedule_exception';
	case RESCHEDULE_INVALID_ACTION_ID = 'reschedule_invalid_action_id';
	case UNKNOWN_JOB_TYPE             = 'unknown_job_type';
}
