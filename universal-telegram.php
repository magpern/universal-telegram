<?php
/**
 * Plugin Name:       Telegram Operations Hub for WordPress
 * Plugin URI:        https://github.com/magpern/universal-telegram
 * Description:       Foundation milestone. No end-user functionality ships yet.
 * Version:           0.0.1
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

define( 'UNIVERSAL_TELEGRAM_VERSION', '0.0.1' );
define( 'UNIVERSAL_TELEGRAM_PLUGIN_FILE', __FILE__ );

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/vendor/woocommerce/action-scheduler/action-scheduler.php';

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
