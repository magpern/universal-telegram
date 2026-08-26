<?php
/**
 * Legacy-chat quiescence state machine states.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Migration;

/**
 * The four states of the legacy-chat quiescence state machine
 * (docs/adr/0040 §4/§6):
 * `idle → draining → quiescent → replaying → idle`.
 */
enum QuiescenceState: string {
	case IDLE      = 'idle';
	case DRAINING  = 'draining';
	case QUIESCENT = 'quiescent';
	case REPLAYING = 'replaying';
}
