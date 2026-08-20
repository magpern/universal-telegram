<?php
/**
 * Integration-test bootstrap. Runs against a real, version-matched
 * WordPress core and test-suite scaffold fetched by tests/bin/install-wp.sh.
 *
 * @package UniversalTelegram
 */

require dirname( __DIR__, 2 ) . '/vendor/autoload.php';

$wp_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $wp_tests_dir ) {
	fwrite( STDERR, "WP_TESTS_DIR is not set. Run tests/bin/install-wp.sh <wp-version> and source /tmp/ut-wp-env.sh first.\n" );
	exit( 1 );
}

require_once $wp_tests_dir . '/includes/functions.php';

/**
 * Loads the plugin under test (and WooCommerce, when the WooCommerce-present
 * configuration is active) as an "MU plugin" for the WordPress test
 * framework, exactly as the framework's own documented pattern expects.
 */
function universal_telegram_manually_load_plugin() {
	if ( getenv( 'UT_TEST_WC_ACTIVE' ) ) {
		require WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
	}

	require dirname( __DIR__, 2 ) . '/universal-telegram.php';
}
tests_add_filter( 'muplugins_loaded', 'universal_telegram_manually_load_plugin' );

require $wp_tests_dir . '/includes/bootstrap.php';
