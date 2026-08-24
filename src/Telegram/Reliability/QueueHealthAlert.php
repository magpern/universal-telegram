<?php
/**
 * Queue-health alert computation.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Telegram\Reliability;

use UniversalTelegram\Telegram\Configuration\BotProfileRepository;
use UniversalTelegram\Telegram\Outbound\OutboundMessageRepository;

/**
 * Active when any of — a non-zero dead-letter count, any circuit breaker
 * open, any message pending longer than a configurable staleness
 * threshold, or any bot with a stale unresolved webhook registration/rotation
 * (docs/adr/0013, WP10) — holds true. Built on OutboundMessageRepository,
 * CircuitBreaker, and BotProfileRepository's own read surfaces, never
 * duplicating their counting logic. The stale-registration condition is
 * read-only: this class never writes to, discards, promotes, or replaces
 * any secret or any other bot field. Surfaced only through local WordPress
 * admin surfaces, never a Telegram message (docs/adr/0014) — the transport
 * itself may be the thing that is failing.
 */
final class QueueHealthAlert {

	public const ALERT_CACHE_TRANSIENT = 'universal_telegram_queue_health_alert_active';

	/**
	 * Clears the cached admin-banner alert state so the next wp-admin
	 * request recomputes it immediately after an operator changes the
	 * dead-letter queue.
	 */
	public static function bust_alert_cache(): void {
		delete_transient( self::ALERT_CACHE_TRANSIENT );
	}

	/**
	 * Constructor.
	 *
	 * @param OutboundMessageRepository $messages Dead-letter and stale-pending counts.
	 * @param CircuitBreaker            $breaker  Open-breaker detection.
	 * @param BotProfileRepository      $bots     Stale unresolved registration/rotation detection.
	 */
	public function __construct(
		private readonly OutboundMessageRepository $messages,
		private readonly CircuitBreaker $breaker,
		private readonly BotProfileRepository $bots
	) {}

	/**
	 * Whether the alert condition is currently active.
	 *
	 * @param int $stale_pending_threshold_seconds       The message-staleness threshold, in seconds.
	 * @param int $stale_registration_threshold_hours     The registration/rotation-staleness threshold, in hours.
	 *
	 * @return bool
	 */
	public function is_active( int $stale_pending_threshold_seconds, int $stale_registration_threshold_hours = 24 ): bool {
		$details = $this->details( $stale_pending_threshold_seconds, $stale_registration_threshold_hours );

		return $details['dead_letter_count'] > 0
			|| $details['any_circuit_breaker_open']
			|| $details['stale_pending_count'] > 0
			|| $details['stale_unresolved_registrations_count'] > 0;
	}

	/**
	 * The individual condition values behind is_active(), for the
	 * diagnostics page's own detailed view.
	 *
	 * @param int $stale_pending_threshold_seconds       The message-staleness threshold, in seconds.
	 * @param int $stale_registration_threshold_hours     The registration/rotation-staleness threshold, in hours.
	 *
	 * @return array{dead_letter_count: int, any_circuit_breaker_open: bool, stale_pending_count: int, stale_unresolved_registrations_count: int}
	 */
	public function details( int $stale_pending_threshold_seconds, int $stale_registration_threshold_hours = 24 ): array {
		return array(
			'dead_letter_count'                    => $this->messages->dead_letter_count(),
			'any_circuit_breaker_open'             => $this->breaker->has_any_open(),
			'stale_pending_count'                  => $this->messages->stale_pending_count( $stale_pending_threshold_seconds ),
			'stale_unresolved_registrations_count' => $this->bots->count_stale_unresolved_registrations( $stale_registration_threshold_hours ),
		);
	}
}
