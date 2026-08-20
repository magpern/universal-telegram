<?php
/**
 * Plugin settings.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Core\Configuration;

/**
 * Sole owner of the `universal_telegram_settings` option. M00 defines no
 * settings fields of its own; later milestones extend defaults() and
 * sanitize() as they introduce real configuration.
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
		return array();
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

		return $input;
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
