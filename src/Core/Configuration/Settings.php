<?php
/**
 * Plugin settings.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Core\Configuration;

/**
 * Sole owner of the `universal_telegram_settings` option. M00 defines one
 * field of its own, remove_data_on_uninstall (consulted by
 * Core\Lifecycle\Uninstaller). M01 adds seven Telegram-specific numeric
 * fields (docs/adr/0014, plan section 8) — frozen defaults, each an
 * ordinary Settings-configurable value an administrator may adjust
 * post-deployment. Later milestones extend defaults() and sanitize()
 * further as they introduce their own configuration.
 */
final class Settings {

	public const OPTION_NAME  = 'universal_telegram_settings';
	public const OPTION_GROUP = 'universal_telegram_settings_group';

	/**
	 * Registers the option with the Settings API.
	 */
	public function register(): void {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => $this->defaults(),
			)
		);
	}

	/**
	 * Pure defaults. WordPress-free, unit-testable without a bootstrap.
	 *
	 * @return array<string, mixed>
	 */
	public function defaults(): array {
		return array(
			'remove_data_on_uninstall'                    => false,
			'telegram_message_retention_days'             => 30,
			'telegram_delivery_log_retention_days'        => 90,
			'telegram_max_pending_seconds'                => 86400,
			'telegram_webhook_max_body_bytes'             => 1048576,
			'telegram_stale_pending_alert_seconds'        => 1800,
			'telegram_rate_limit_fallback_wait_seconds'   => 30,
			'telegram_webhook_rotation_max_pending_hours' => 24,
			'event_retention_days'                        => 90,
			'dispatch_log_retention_days'                 => 90,
			'fatal_marker_retention_days'                 => 30,
			'visitor_tracking_enabled'                    => false,
			'visitor_family_page_views'                   => false,
			'visitor_family_navigation'                   => false,
			'visitor_family_search'                       => false,
			'visitor_family_clicks'                       => false,
			'visitor_family_errors'                       => false,
			'visitor_family_commerce'                     => false,
			'visitor_consent_mode'                        => 'required',
			'visitor_sampling_percent'                    => 100,
			'visitor_click_target_allowlist'              => array(),
			'visitor_exclude_administrators'              => true,
			'chat_widget_enabled'                         => false,
			'chat_widget_preset'                          => 'theme',
			'chat_widget_geometry'                        => 'round',
			'chat_widget_motion_default'                  => 'standard',
			'chat_widget_participant_label_visitor'       => 'You',
			'chat_widget_participant_label_operator'      => 'Support',
			'chat_widget_allow_anonymous'                 => false,
			'visitor_digest_enabled'                      => false,
			'visitor_digest_bot_id'                       => null,
			'visitor_digest_destination_id'               => null,
			'visitor_digest_threshold'                    => 50,
			'visitor_digest_max_wait_minutes'             => 15,
			'operational_summary_enabled'                 => false,
			'operational_summary_bot_id'                  => null,
			'operational_summary_destination_id'          => null,
			'operational_summary_hour_utc'                => 6,
			'alert_bot_id'                                => null,
			'alert_destination_id'                        => null,
			'alert_checkout_failure_count_enabled'        => false,
			'alert_checkout_failure_count_threshold'      => 10,
			'alert_order_failure_spike_enabled'           => false,
			'alert_order_failure_spike_threshold'         => 10,
			'alert_js_error_spike_enabled'                => false,
			'alert_js_error_spike_threshold'              => 50,
		);
	}

	/**
	 * Pure sanitizer. Never throws; forgiving of malformed input so
	 * persistence always succeeds.
	 *
	 * @param mixed $input Raw submitted value.
	 * @return array<string, mixed>
	 */
	public function sanitize( $input ): array {
		if ( ! is_array( $input ) ) {
			return $this->defaults();
		}

		$sanitized = $this->defaults();

		if ( isset( $input['remove_data_on_uninstall'] ) ) {
			$sanitized['remove_data_on_uninstall'] = (bool) $input['remove_data_on_uninstall'];
		}

		// visitor_family_clicks and visitor_click_target_allowlist are
		// deliberately absent from $boolean_fields/the allowlist handling
		// below (bug-fix authorization, corrective removal): this developer-
		// only feature requires identifiers ordinary administrators cannot
		// configure meaningfully, so no submitted input — crafted or
		// otherwise — can ever set them to anything but their defaults.
		$boolean_fields = array(
			'visitor_tracking_enabled',
			'visitor_family_page_views',
			'visitor_family_navigation',
			'visitor_family_search',
			'visitor_family_errors',
			'visitor_family_commerce',
			'visitor_exclude_administrators',
			'chat_widget_enabled',
			'chat_widget_allow_anonymous',
			'visitor_digest_enabled',
			'operational_summary_enabled',
			'alert_checkout_failure_count_enabled',
			'alert_order_failure_spike_enabled',
			'alert_js_error_spike_enabled',
		);

		foreach ( $boolean_fields as $field ) {
			if ( isset( $input[ $field ] ) ) {
				$sanitized[ $field ] = (bool) $input[ $field ];
			}
		}

		if ( isset( $input['visitor_consent_mode'] ) && in_array( $input['visitor_consent_mode'], array( 'required', 'disabled' ), true ) ) {
			$sanitized['visitor_consent_mode'] = $input['visitor_consent_mode'];
		}

		if ( isset( $input['chat_widget_preset'] ) && in_array( $input['chat_widget_preset'], array( 'theme', 'classic', 'modern', 'minimal' ), true ) ) {
			$sanitized['chat_widget_preset'] = $input['chat_widget_preset'];
		}

		if ( isset( $input['chat_widget_geometry'] ) && in_array( $input['chat_widget_geometry'], array( 'round', 'square' ), true ) ) {
			$sanitized['chat_widget_geometry'] = $input['chat_widget_geometry'];
		}

		if ( isset( $input['chat_widget_motion_default'] ) && in_array( $input['chat_widget_motion_default'], array( 'standard', 'reduced' ), true ) ) {
			$sanitized['chat_widget_motion_default'] = $input['chat_widget_motion_default'];
		}

		foreach ( array( 'chat_widget_participant_label_visitor', 'chat_widget_participant_label_operator' ) as $label_field ) {
			if ( isset( $input[ $label_field ] ) && is_string( $input[ $label_field ] ) ) {
				$trimmed_label = trim( $input[ $label_field ] );

				if ( '' !== $trimmed_label && mb_strlen( $trimmed_label, 'UTF-8' ) <= 40 ) {
					$sanitized[ $label_field ] = $trimmed_label;
				}
			}
		}

		if ( isset( $input['visitor_sampling_percent'] ) && is_numeric( $input['visitor_sampling_percent'] ) ) {
			$sanitized['visitor_sampling_percent'] = max( 1, min( 100, (int) $input['visitor_sampling_percent'] ) );
		}

		// visitor_digest_bot_id/visitor_digest_destination_id are stored as
		// plain int references only; existence and eligibility (an active
		// bot, an eligible, non-conversation-linked, enabled destination
		// belonging to that bot) are re-validated live on every read by
		// Automations\Digest\DigestEligibility, never assumed correct here
		// (docs/plans/m11a-visitor-activity-digests-plan-v1.md §4).
		foreach ( array( 'visitor_digest_bot_id', 'visitor_digest_destination_id' ) as $reference_field ) {
			if ( isset( $input[ $reference_field ] ) && is_numeric( $input[ $reference_field ] ) && (int) $input[ $reference_field ] > 0 ) {
				$sanitized[ $reference_field ] = (int) $input[ $reference_field ];
			} else {
				$sanitized[ $reference_field ] = null;
			}
		}

		if ( isset( $input['visitor_digest_threshold'] ) && is_numeric( $input['visitor_digest_threshold'] ) ) {
			$sanitized['visitor_digest_threshold'] = max( 10, min( 500, (int) $input['visitor_digest_threshold'] ) );
		}

		if ( isset( $input['visitor_digest_max_wait_minutes'] ) && is_numeric( $input['visitor_digest_max_wait_minutes'] ) ) {
			$sanitized['visitor_digest_max_wait_minutes'] = max( 5, min( 60, (int) $input['visitor_digest_max_wait_minutes'] ) );
		}

		// operational_summary_bot_id/operational_summary_destination_id and
		// alert_bot_id/alert_destination_id are stored as plain int
		// references only, re-validated live on every read by the same
		// Automations\Digest\DigestEligibility eligibility rule M11A already
		// established (docs/plans/m11b-digests-and-operational-intelligence-plan-v1.md §5).
		foreach ( array( 'operational_summary_bot_id', 'operational_summary_destination_id', 'alert_bot_id', 'alert_destination_id' ) as $reference_field ) {
			if ( isset( $input[ $reference_field ] ) && is_numeric( $input[ $reference_field ] ) && (int) $input[ $reference_field ] > 0 ) {
				$sanitized[ $reference_field ] = (int) $input[ $reference_field ];
			} else {
				$sanitized[ $reference_field ] = null;
			}
		}

		if ( isset( $input['operational_summary_hour_utc'] ) && is_numeric( $input['operational_summary_hour_utc'] ) ) {
			$sanitized['operational_summary_hour_utc'] = max( 0, min( 23, (int) $input['operational_summary_hour_utc'] ) );
		}

		if ( isset( $input['alert_checkout_failure_count_threshold'] ) && is_numeric( $input['alert_checkout_failure_count_threshold'] ) ) {
			$sanitized['alert_checkout_failure_count_threshold'] = max( 3, min( 100, (int) $input['alert_checkout_failure_count_threshold'] ) );
		}

		if ( isset( $input['alert_order_failure_spike_threshold'] ) && is_numeric( $input['alert_order_failure_spike_threshold'] ) ) {
			$sanitized['alert_order_failure_spike_threshold'] = max( 3, min( 100, (int) $input['alert_order_failure_spike_threshold'] ) );
		}

		if ( isset( $input['alert_js_error_spike_threshold'] ) && is_numeric( $input['alert_js_error_spike_threshold'] ) ) {
			$sanitized['alert_js_error_spike_threshold'] = max( 5, min( 500, (int) $input['alert_js_error_spike_threshold'] ) );
		}

		$positive_integer_fields = array(
			'telegram_message_retention_days',
			'telegram_delivery_log_retention_days',
			'telegram_max_pending_seconds',
			'telegram_webhook_max_body_bytes',
			'telegram_stale_pending_alert_seconds',
			'telegram_rate_limit_fallback_wait_seconds',
			'telegram_webhook_rotation_max_pending_hours',
			'event_retention_days',
			'dispatch_log_retention_days',
			'fatal_marker_retention_days',
		);

		foreach ( $positive_integer_fields as $field ) {
			if ( isset( $input[ $field ] ) && is_numeric( $input[ $field ] ) && (int) $input[ $field ] > 0 ) {
				$sanitized[ $field ] = (int) $input[ $field ];
			}
		}

		return $sanitized;
	}

	/**
	 * Reads the current stored settings, merged over defaults.
	 *
	 * @return array<string, mixed>
	 */
	public function get(): array {
		$stored = get_option( self::OPTION_NAME, $this->defaults() );

		if ( ! is_array( $stored ) ) {
			return $this->defaults();
		}

		return array_merge( $this->defaults(), $stored );
	}
}
