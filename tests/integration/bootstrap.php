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

		// Requiring the main file does not run WooCommerce's own
		// activation routine (nothing "activates" it through WordPress'
		// real activation flow here), so its own tables are never
		// created. WC_Install::install() is the standard, documented way
		// WooCommerce extension test suites provision those tables
		// directly, without a real activation request. It needs both
		// pluggable.php (current_user_can(), etc. — loaded by
		// `plugins_loaded` time) and $wp_rewrite (set up just before
		// `init` fires), so it runs on `init` itself, at priority -10 —
		// strictly before WooCommerce's own `init` callback, registered
		// at priority 0, which would otherwise query these tables before
		// they exist.
		add_action(
			'init',
			function () {
				if ( class_exists( 'WC_Install' ) ) {
					\WC_Install::install();
				}
			},
			-10
		);
	}

	require dirname( __DIR__, 2 ) . '/universal-telegram.php';
}
tests_add_filter( 'muplugins_loaded', 'universal_telegram_manually_load_plugin' );

require $wp_tests_dir . '/includes/bootstrap.php';
