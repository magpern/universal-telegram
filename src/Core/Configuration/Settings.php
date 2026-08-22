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
			'chat_widget_preset'                          => 'modern',
			'chat_widget_geometry'                        => 'round',
			'chat_widget_motion_default'                  => 'standard',
			'chat_widget_participant_label_visitor'       => 'You',
			'chat_widget_participant_label_operator'      => 'Support',
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

		$boolean_fields = array(
			'visitor_tracking_enabled',
			'visitor_family_page_views',
			'visitor_family_navigation',
			'visitor_family_search',
			'visitor_family_clicks',
			'visitor_family_errors',
			'visitor_family_commerce',
			'visitor_exclude_administrators',
			'chat_widget_enabled',
		);

		foreach ( $boolean_fields as $field ) {
			if ( isset( $input[ $field ] ) ) {
				$sanitized[ $field ] = (bool) $input[ $field ];
			}
		}

		if ( isset( $input['visitor_consent_mode'] ) && in_array( $input['visitor_consent_mode'], array( 'required', 'disabled' ), true ) ) {
			$sanitized['visitor_consent_mode'] = $input['visitor_consent_mode'];
		}

		if ( isset( $input['chat_widget_preset'] ) && in_array( $input['chat_widget_preset'], array( 'classic', 'modern', 'minimal' ), true ) ) {
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

		if ( isset( $input['visitor_click_target_allowlist'] ) && is_array( $input['visitor_click_target_allowlist'] ) ) {
			$allowlist = array();

			foreach ( array_slice( $input['visitor_click_target_allowlist'], 0, 8 ) as $key ) {
				if ( is_string( $key ) && '' !== $key ) {
					$normalized = strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', $key ) ?? '' );
					if ( '' !== $normalized ) {
						$allowlist[] = $normalized;
					}
				}
			}

			$sanitized['visitor_click_target_allowlist'] = array_values( array_unique( $allowlist ) );
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
