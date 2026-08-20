<?php
/**
 * WooCommerce presence detection.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Integrations\WooCommerce;

/**
 * The single place every later module asks whether WooCommerce is present,
 * rather than each reimplementing its own check. WooCommerce is a
 * genuinely optional integration (docs/adr/0003); this class implements
 * that decision, it does not introduce it.
 */
final class WooCommerceSupport {

	private const MIN_VERSION = '8.0';

	/**
	 * Whether a sufficiently recent WooCommerce is active.
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return false;
		}

		if ( ! defined( 'WC_VERSION' ) ) {
			return false;
		}

		return version_compare( WC_VERSION, self::MIN_VERSION, '>=' );
	}
}
