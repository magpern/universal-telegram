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
 * Core\Lifecycle\Uninstaller); later milestones extend defaults() and
 * sanitize() as they introduce further configuration.
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
			'remove_data_on_uninstall' => false,
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
