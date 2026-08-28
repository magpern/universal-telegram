<?php
/**
 * Diagnostics report data.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Administration\Diagnostics;

use UniversalTelegram\Audit\AuditLogRepository;
use UniversalTelegram\Automations\DispatchLogRepository;
use UniversalTelegram\Automations\Intelligence\AlertRepository;
use UniversalTelegram\Automations\Intelligence\IntelligenceSettings;
use UniversalTelegram\Automations\NotificationRuleRepository;
use UniversalTelegram\Automations\RuleEvaluator;
use UniversalTelegram\Events\EventHistoryRepository;
use UniversalTelegram\Events\EventSource;
use UniversalTelegram\Events\RetentionCleanup;
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
	 * @param QueueHealth                $queue_health              Pending/failed action counts.
	 * @param AuditLogRepository         $audit_log_repository      The recent-entries read path.
	 * @param WooCommerceSupport         $woocommerce_support        WooCommerce-presence detection.
	 * @param SchemaHealth               $schema_health              The current schema-availability state.
	 * @param BotProfileRepository       $bots                       Telegram bot counts.
	 * @param DestinationRepository      $destinations               Telegram destination counts.
	 * @param QueueHealthAlert           $queue_health_alert          The Telegram queue-health alert's own details.
	 * @param EventHistoryRepository     $event_history       Event-history counts.
	 * @param NotificationRuleRepository $notification_rules  Rule counts.
	 * @param DispatchLogRepository      $dispatch_log        Dispatch-log failure/stuck-claim counts.
	 * @param int                        $stale_pending_threshold_seconds The message-staleness threshold, in seconds.
	 * @param int                        $stale_registration_threshold_hours The registration-staleness threshold, in hours.
	 * @param IntelligenceSettings|null  $intelligence_settings Typed M11B intelligence settings reader.
	 * @param AlertRepository|null       $alert_repository       Threshold alert state.
	 */
	public function __construct(
		private readonly QueueHealth $queue_health,
		private readonly AuditLogRepository $audit_log_repository,
		private readonly WooCommerceSupport $woocommerce_support,
		private readonly SchemaHealth $schema_health,
		private readonly BotProfileRepository $bots,
		private readonly DestinationRepository $destinations,
		private readonly QueueHealthAlert $queue_health_alert,
		private readonly EventHistoryRepository $event_history,
		private readonly NotificationRuleRepository $notification_rules,
		private readonly DispatchLogRepository $dispatch_log,
		private readonly int $stale_pending_threshold_seconds = 1800,
		private readonly int $stale_registration_threshold_hours = 24,
		private readonly ?IntelligenceSettings $intelligence_settings = null,
		private readonly ?AlertRepository $alert_repository = null
	) {}

	/**
	 * The full diagnostics key/value map rendered by DiagnosticsPage.
	 *
	 * @return array<string, mixed>
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
			'plugin_version'                           => defined( 'UNIVERSAL_TELEGRAM_VERSION' ) ? UNIVERSAL_TELEGRAM_VERSION : 'unknown',
			'php_version'                              => PHP_VERSION,
			'wp_version'                               => get_bloginfo( 'version' ),
			'woocommerce_active'                       => $this->woocommerce_support->is_active(),
			'woocommerce_hpos_enabled'                 => $this->woocommerce_hpos_enabled(),
			// Restates $woocommerce_support->is_active() under an explicit
			// diagnostics label — M03's WooCommerce event-emitter gating
			// (Core\Plugin::init()) is exactly 1:1 with WC activity, so
			// there is no separate state to track (M03 plan §6).
			'woocommerce_event_emitters_registered'    => $this->woocommerce_support->is_active(),
			'queue_pending'                            => $this->schema_health->is_available() ? $this->queue_health->pending_count() : 0,
			'queue_failed'                             => $this->schema_health->is_available() ? $this->queue_health->failed_count() : 0,
			'queue_oldest_pending_age_seconds'         => $this->schema_health->is_available() ? $this->queue_health->oldest_pending_age_seconds() : 0,
			// Truthful, non-content signals only (docs/adr/0023 §3): the
			// routine-success path is never audited, so its absence here is
			// not itself a signal — combine with queue age and each
			// message's own recorded delivery outcome, never these two
			// counts alone.
			'queue_expedited_dispatch_declined_concurrency_24h' => $this->audit_log_repository->count_by_action_24h( 'expedited_dispatch_declined_concurrency' ),
			'queue_expedited_dispatch_unavailable_24h' => $this->audit_log_repository->count_by_action_24h( 'expedited_dispatch_unavailable' ),
			'telegram_bot_count'                       => count( $bots ),
			'telegram_destination_count'               => $destination_count,
			'telegram_dead_letter_count'               => $alert_details['dead_letter_count'],
			'telegram_any_circuit_breaker_open'        => $alert_details['any_circuit_breaker_open'],
			'telegram_stale_pending_count'             => $alert_details['stale_pending_count'],
			'telegram_stale_unresolved_registrations_count' => $alert_details['stale_unresolved_registrations_count'],
			'telegram_queue_health_alert_active'       => $this->schema_health->is_available()
				? $this->queue_health_alert->is_active( $this->stale_pending_threshold_seconds, $this->stale_registration_threshold_hours )
				: false,
			'automations_event_count_24h'              => $this->schema_health->is_available() ? $this->event_history->count_24h() : 0,
			'automations_rule_count'                   => $this->schema_health->is_available() ? $this->notification_rules->count_all() : 0,
			'automations_enabled_rule_count'           => $this->schema_health->is_available() ? $this->notification_rules->count_enabled() : 0,
			'automations_dispatch_failed_count_24h'    => $this->schema_health->is_available() ? $this->dispatch_log->failed_count_24h() : 0,
			'automations_stuck_claim_count'            => $this->schema_health->is_available() ? $this->dispatch_log->stuck_claim_count() : 0,
			'automations_stale_fatal_markers_dropped_count' => (int) get_option( RetentionCleanup::STALE_FATAL_MARKERS_DROPPED_OPTION, 0 ),
			'automations_last_evaluation_error_code'   => (string) get_option( RuleEvaluator::LAST_EVALUATION_ERROR_CODE_OPTION, 'none' ),
			'visitor_events_recorded_24h'              => $this->schema_health->is_available() ? $this->event_history->count_24h_by_source( EventSource::VISITOR->value ) : 0,
			'visitor_events_rejected_24h'              => $this->audit_log_repository->count_by_action_24h( 'visitor_events.rejected' ),
			'visitor_events_rate_limited_24h'          => $this->audit_log_repository->count_by_action_24h( 'visitor_events.rate_limited' ),
			'visitor_events_bot_filtered_24h'          => $this->audit_log_repository->count_by_action_24h( 'visitor_events.bot_filtered' ),
			// Administrative bot commands (M08, ADR-0027): read-only counts
			// of the two rejection outcomes only — never a raw Telegram id,
			// bot_id alone carries no sensitive meaning. No command-content
			// or success-count breakdown is surfaced here (successful reads
			// are unaudited by design, matching this codebase's existing
			// precedent elsewhere in this report).
			'bot_commands_active'                      => $this->schema_health->is_available(),
			'bot_commands_rejected_unauthorized_24h'   => $this->audit_log_repository->count_by_action_24h( 'bot_command.rejected_unauthorized' ),
			'bot_commands_rejected_wrong_context_24h'  => $this->audit_log_repository->count_by_action_24h( 'bot_command.rejected_wrong_context' ),
			'recent_audit_entries'                     => $this->audit_log_repository->recent( 20 ),
		) + $this->intelligence_diagnostics();
	}

	/**
	 * The M11B operational-intelligence diagnostics keys
	 * (docs/plans/m11b-digests-and-operational-intelligence-plan-v1.md §5).
	 * Every value defaults safely (disabled/never_run) when the M11B
	 * dependencies are not supplied (pre-M11B callers) or the schema is
	 * unavailable.
	 *
	 * @return array<string, mixed>
	 */
	private function intelligence_diagnostics(): array {
		if ( null === $this->intelligence_settings || null === $this->alert_repository ) {
			return array();
		}

		$keys = array();

		foreach ( IntelligenceSettings::ALERT_TYPES as $alert_type ) {
			$keys[ 'alert_' . $alert_type . '_enabled' ]       = $this->intelligence_settings->alert_enabled( $alert_type );
			$keys[ 'alert_' . $alert_type . '_last_fired_at' ] = $this->schema_health->is_available() ? $this->alert_repository->last_fired_at( $alert_type ) : null;
		}

		return $keys;
	}

	/**
	 * Whether WooCommerce's HPOS custom-orders-table storage is enabled.
	 * Returns false when WooCommerce is absent (the utility class won't
	 * exist), guarded by both class_exists() and is_active() (M03 plan §6).
	 *
	 * @return bool
	 */
	private function woocommerce_hpos_enabled(): bool {
		if ( ! $this->woocommerce_support->is_active() ) {
			return false;
		}

		if ( ! class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
			return false;
		}

		return \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}
}
