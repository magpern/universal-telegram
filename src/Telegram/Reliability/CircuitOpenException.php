<?php
/**
 * Circuit breaker open.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Telegram\Reliability;

use Exception;

/**
 * Thrown by CircuitBreaker::assert_may_attempt() when the breaker is not
 * currently allowing an attempt. Never propagated out of
 * Telegram\Outbound\SendMessageHandler — caught there and turned into a
 * non-throwing deferral at next_probe_at (docs/adr/0014, A7).
 */
final class CircuitOpenException extends Exception {

	/**
	 * Constructor.
	 *
	 * @param int|null $next_probe_at When the breaker will next allow a
	 *                                 trial attempt, or null if it is open
	 *                                 indefinitely (TOKEN_INVALID).
	 */
	public function __construct( private readonly ?int $next_probe_at ) {
		parent::__construct( 'Circuit breaker is open.' );
	}

	/**
	 * When the breaker will next allow a trial attempt, or null if it is
	 * open indefinitely.
	 *
	 * @return int|null
	 */
	public function next_probe_at(): ?int {
		return $this->next_probe_at;
	}
}
