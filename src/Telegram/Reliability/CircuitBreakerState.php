<?php
/**
 * Circuit breaker state.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Telegram\Reliability;

/**
 * The three states of one (scope_type, scope_id) circuit breaker
 * (docs/adr/0014).
 */
enum CircuitBreakerState: string {
	case CLOSED    = 'closed';
	case OPEN      = 'open';
	case HALF_OPEN = 'half_open';
}
