<?php
/**
 * Diagnostics report data.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Administration\Diagnostics;

use UniversalTelegram\Audit\AuditLogRepository;
use UniversalTelegram\Integrations\WooCommerce\WooCommerceSupport;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Queue\QueueHealth;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;
use UniversalTelegram\Telegram\Configuration\DestinationRepository;
use UniversalTelegram\Telegram\Reliability\QueueHealthAlert;

/**
 * Gathers plugin, PHP, WordPress, and WooCommerce version information, the
 * queue's own pending/failed counts, the most recent (already redacted)
 * audit-log entries, and — extended by M01, WP11, no new Diagnostics
 * classes introduced — Telegram bot/destination counts and the
 * queue-health alert's own detail values.
 */
final class DiagnosticsReport {

	/**
	 * Constructor.
	 *
	 * @param QueueHealth           $queue_health              Pending/failed action counts.
	 * @param AuditLogRepository    $audit_log_repository      The recent-entries read path.
	 * @param WooCommerceSupport    $woocommerce_support        WooCommerce-presence detection.
	 * @param SchemaHealth          $schema_health              The current schema-availability state.
	 * @param BotProfileRepository  $bots                       Telegram bot counts.
	 * @param DestinationRepository $destinations               Telegram destination counts.
	 * @param QueueHealthAlert      $queue_health_alert          The Telegram queue-health alert's own details.
	 * @param int                   $stale_pending_threshold_seconds The message-staleness threshold, in seconds.
	 * @param int                   $stale_registration_threshold_hours The registration-staleness threshold, in hours.
	 */
	public function __construct(
		private readonly QueueHealth $queue_health,
		private readonly AuditLogRepository $audit_log_repository,
		private readonly WooCommerceSupport $woocommerce_support,
		private readonly SchemaHealth $schema_health,
		private readonly BotProfileRepository $bots,
		private readonly DestinationRepository $destinations,
		private readonly QueueHealthAlert $queue_health_alert,
		private readonly int $stale_pending_threshold_seconds = 1800,
		private readonly int $stale_registration_threshold_hours = 24
	) {}

	/**
	 * Generates the report data.
	 *
	 * @return array{
	 *     plugin_version: string,
	 *     php_version: string,
	 *     wp_version: string,
	 *     woocommerce_active: bool,
	 *     queue_pending: int,
	 *     queue_failed: int,
	 *     telegram_bot_count: int,
	 *     telegram_destination_count: int,
	 *     telegram_dead_letter_count: int,
	 *     telegram_any_circuit_breaker_open: bool,
	 *     telegram_stale_pending_count: int,
	 *     telegram_stale_unresolved_registrations_count: int,
	 *     telegram_queue_health_alert_active: bool,
	 *     recent_audit_entries: array<int, array<string, mixed>>
	 * }
	 */
	public function generate(): array {
		$bots              = $this->schema_health->is_available() ? $this->bots->all() : array();
		$destination_count = 0;

		foreach ( $bots as $bot ) {
			$destination_count += count( $this->destinations->for_bot( $bot->id() ) );
		}

		$alert_details = $this->schema_health->is_available()
			? $this->queue_health_alert->details( $this->stale_pending_threshold_seconds, $this->stale_registration_threshold_hours )
			: array(
				'dead_letter_count'                    => 0,
				'any_circuit_breaker_open'             => false,
				'stale_pending_count'                  => 0,
				'stale_unresolved_registrations_count' => 0,
			);

		return array(
			'plugin_version'                     => defined( 'UNIVERSAL_TELEGRAM_VERSION' ) ? UNIVERSAL_TELEGRAM_VERSION : 'unknown',
			'php_version'                        => PHP_VERSION,
			'wp_version'                         => get_bloginfo( 'version' ),
			'woocommerce_active'                 => $this->woocommerce_support->is_active(),
			'queue_pending'                      => $this->schema_health->is_available() ? $this->queue_health->pending_count() : 0,
			'queue_failed'                       => $this->schema_health->is_available() ? $this->queue_health->failed_count() : 0,
			'telegram_bot_count'                 => count( $bots ),
			'telegram_destination_count'         => $destination_count,
			'telegram_dead_letter_count'         => $alert_details['dead_letter_count'],
			'telegram_any_circuit_breaker_open'  => $alert_details['any_circuit_breaker_open'],
			'telegram_stale_pending_count'       => $alert_details['stale_pending_count'],
			'telegram_stale_unresolved_registrations_count' => $alert_details['stale_unresolved_registrations_count'],
			'telegram_queue_health_alert_active' => $this->schema_health->is_available()
				? $this->queue_health_alert->is_active( $this->stale_pending_threshold_seconds, $this->stale_registration_threshold_hours )
				: false,
			'recent_audit_entries'               => $this->audit_log_repository->recent( 20 ),
		);
	}
}
