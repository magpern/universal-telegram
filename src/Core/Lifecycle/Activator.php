<?php
/**
 * Plugin activation.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Core\Lifecycle;

/**
 * Network-wide multisite activation is explicitly refused, not partially
 * supported. Per-site activation, including within a multisite network,
 * is fully supported and behaves identically to a non-multisite install.
 */
final class Activator {

	/**
	 * Activation callback. Schema provisioning itself happens lazily, on
	 * the next `plugins_loaded`, via Core\Plugin::init() -> Migrator; this
	 * method only gates network-wide activation and grants capabilities.
	 *
	 * @param bool $network_wide Whether WordPress is activating network-wide.
	 */
	public function activate( bool $network_wide ): void {
		if ( $network_wide ) {
			wp_die(
				esc_html__(
					'Telegram Operations Hub cannot be network-activated. Activate it individually on each site that needs it.',
					'universal-telegram'
				)
			);
		}
	}
}
