<?php
/**
 * Cross-plugin interoperability bootstrap: loads BOTH universal-telegram
 * (this checkout) and universal-support-chat (the real sibling checkout,
 * linked into wp-content/plugins by tests/bin/install-support-chat.sh) as
 * "MU plugins" for the WordPress test framework, so the interop suite
 * exercises real Contract v1 client/server code on both sides in one
 * disposable WordPress install.
 *
 * @package UniversalTelegram
 */

require dirname( __DIR__, 3 ) . '/vendor/autoload.php';

$wp_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $wp_tests_dir ) {
	fwrite( STDERR, "WP_TESTS_DIR is not set. Run tests/bin/install-wp.sh <wp-version>, source /tmp/ut-wp-env.sh, then tests/bin/install-support-chat.sh first.\n" );
	exit( 1 );
}

require_once $wp_tests_dir . '/includes/functions.php';

/**
 * Loads both plugins under test and provisions Support Chat's schema (it
 * migrates lazily on its own `plugins_loaded`, so simply requiring its main
 * file is sufficient — unlike WooCommerce's WC_Install::install() pattern,
 * no separate installer call is needed).
 */
function universal_telegram_interop_manually_load_plugins() {
	$support_chat_main = WP_PLUGIN_DIR . '/universal-support-chat/universal-support-chat.php';

	if ( ! file_exists( $support_chat_main ) ) {
		fwrite( STDERR, "universal-support-chat is not linked into wp-content/plugins. Run tests/bin/install-support-chat.sh first.\n" );
		exit( 1 );
	}

	// Support Chat's own composer autoloader (UniversalSupportChat\\...),
	// loaded alongside this plugin's autoloader (UniversalTelegram\\...) —
	// two independent PSR-4 autoloaders coexist without collision.
	require WP_PLUGIN_DIR . '/universal-support-chat/vendor/autoload.php';
	require $support_chat_main;

	require dirname( __DIR__, 3 ) . '/universal-telegram.php';
}
tests_add_filter( 'muplugins_loaded', 'universal_telegram_interop_manually_load_plugins' );

require $wp_tests_dir . '/includes/bootstrap.php';
