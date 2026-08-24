<?php
/**
 * Plugin Name:       Telegram Operations Hub for WordPress
 * Plugin URI:        https://github.com/magpern/universal-telegram
 * Description:       Bidirectional Telegram bot connectivity: multiple bot profiles, destinations, outbound queue, authenticated inbound webhook, retry, rate limiting, circuit breaking, dead-letter handling, and diagnostics.
 * Version:           0.14.1
 * Requires at least: 6.9
 * Requires PHP:      8.1
 * Author:            Magnus Pernemark
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       universal-telegram
 *
 * @package UniversalTelegram
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'UNIVERSAL_TELEGRAM_VERSION', '0.14.1' );
define( 'UNIVERSAL_TELEGRAM_PLUGIN_FILE', __FILE__ );

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/vendor/woocommerce/action-scheduler/action-scheduler.php';
require_once __DIR__ . '/universal-telegram-functions.php';

add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				UNIVERSAL_TELEGRAM_PLUGIN_FILE
			);
		}
	}
);

add_action(
	'plugins_loaded',
	function () {
		\UniversalTelegram\Core\Plugin::instance()->init();
	}
);

register_activation_hook(
	__FILE__,
	function ( $network_wide ) {
		( new \UniversalTelegram\Core\Lifecycle\Activator() )->activate( (bool) $network_wide );
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		( new \UniversalTelegram\Core\Lifecycle\Deactivator() )->deactivate();
	}
);
