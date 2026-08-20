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
