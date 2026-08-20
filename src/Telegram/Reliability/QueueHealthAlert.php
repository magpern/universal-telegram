<?php
/**
 * Queue-health alert computation.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Telegram\Reliability;

use UniversalTelegram\Telegram\Outbound\OutboundMessageRepository;

/**
 * Active when any of — a non-zero dead-letter count, any circuit breaker
 * open, or any message pending longer than a configurable staleness
 * threshold — holds true. Built on OutboundMessageRepository and
 * CircuitBreaker's own read surfaces, never duplicating their counting
 * logic. Surfaced only through local WordPress admin surfaces, never a
 * Telegram message (docs/adr/0014) — the transport itself may be the thing
 * that is failing.
 */
final class QueueHealthAlert {

	/**
	 * Constructor.
	 *
	 * @param OutboundMessageRepository $messages Dead-letter and stale-pending counts.
	 * @param CircuitBreaker            $breaker  Open-breaker detection.
	 */
	public function __construct(
		private readonly OutboundMessageRepository $messages,
		private readonly CircuitBreaker $breaker
	) {}

	/**
	 * Whether the alert condition is currently active.
	 *
	 * @param int $stale_pending_threshold_seconds The staleness threshold, in seconds.
	 *
	 * @return bool
	 */
	public function is_active( int $stale_pending_threshold_seconds ): bool {
		$details = $this->details( $stale_pending_threshold_seconds );

		return $details['dead_letter_count'] > 0 || $details['any_circuit_breaker_open'] || $details['stale_pending_count'] > 0;
	}

	/**
	 * The individual condition values behind is_active(), for the
	 * diagnostics page's own detailed view.
	 *
	 * @param int $stale_pending_threshold_seconds The staleness threshold, in seconds.
	 *
	 * @return array{dead_letter_count: int, any_circuit_breaker_open: bool, stale_pending_count: int}
	 */
	public function details( int $stale_pending_threshold_seconds ): array {
		return array(
			'dead_letter_count'        => $this->messages->dead_letter_count(),
			'any_circuit_breaker_open' => $this->breaker->has_any_open(),
			'stale_pending_count'      => $this->messages->stale_pending_count( $stale_pending_threshold_seconds ),
		);
	}
}
