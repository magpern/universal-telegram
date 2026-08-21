<?php
/**
 * Dispatch-log outcome states.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Automations;

/**
 * The seven explicit dispatch-log outcome states (M02 plan §7.5,
 * docs/adr/0016). A row is only ever written to HANDED_OFF_TO_M01 after
 * Telegram\Outbound\MessageDispatcher::send()'s own DispatchResult confirms
 * success; a failed attempt is distinctly recorded as FAILED_BEFORE_HANDOFF,
 * never silently indistinguishable from success. CLAIMED is a transient,
 * in-progress state, never a final outcome an operator should read as
 * "handled."
 */
enum DispatchLogResult: string {
	case CLAIMED                    = 'claimed';
	case REJECTED                   = 'rejected';
	case SKIPPED_DUPLICATE          = 'skipped_duplicate';
	case SKIPPED_COOLDOWN           = 'skipped_cooldown';
	case SKIPPED_DISABLED_REFERENCE = 'skipped_disabled_reference';
	case HANDED_OFF_TO_M01          = 'handed_off_to_m01';
	case FAILED_BEFORE_HANDOFF      = 'failed_before_handoff';
}
