<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Core;

use ReflectionClass;
use Throwable;
use UniversalTelegram\Core\Plugin;
use WP_UnitTestCase;

/**
 * Regression for a production-blocking activation fatal: `rest_url()`
 * (called while wiring `WebhookRegistrationCoordinator`) is unsafe during
 * `plugins_loaded` — the hook `Plugin::init()` runs on — because
 * WordPress' rewrite state (`$GLOBALS['wp_rewrite']`) is not guaranteed to
 * exist yet at that point, producing "Call to a member function
 * using_index_permalinks() on null". Reproduces the real
 * plugins_loaded -> init lifecycle ordering by resetting the composition
 * root and re-running its actual init() with $wp_rewrite unset, in a
 * separate process so the reset never contaminates the rest of the suite.
 */
final class PluginBootstrapTest extends WP_UnitTestCase {

	/**
	 * Resets Plugin's private static singleton to a fresh, un-booted
	 * instance, runs $callback, then restores the original instance.
	 */
	private function with_a_fresh_unbooted_plugin_instance( callable $callback ): void {
		$reflection = new ReflectionClass( Plugin::class );
		$property   = $reflection->getProperty( 'instance' );
		$property->setAccessible( true );

		$original = $property->getValue();

		try {
			$property->setValue( null, null );
			$callback();
		} finally {
			$property->setValue( null, $original );
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_init_never_fatals_when_wp_rewrite_is_not_yet_initialized(): void {
		// get_rest_url() only dereferences $wp_rewrite when pretty
		// permalinks are configured (WordPress core's own
		// get_rest_url()); the real-world crash this reproduces requires
		// a non-empty permalink_structure, matching the reported
		// production activation failure.
		$previous_permalink_structure = get_option( 'permalink_structure' );
		update_option( 'permalink_structure', '/%postname%/' );

		$this->with_a_fresh_unbooted_plugin_instance(
			function () {
				$previous_wp_rewrite = $GLOBALS['wp_rewrite'] ?? null;
				unset( $GLOBALS['wp_rewrite'] );

				$exception = null;

				try {
					Plugin::instance()->init();
				} catch ( Throwable $e ) {
					$exception = $e;
				} finally {
					$GLOBALS['wp_rewrite'] = $previous_wp_rewrite;
				}

				$this->assertNull(
					$exception,
					'Plugin::init() must never fatal when $wp_rewrite is not yet initialized (plugins_loaded timing).' .
					( null !== $exception ? ' Got: ' . $exception->getMessage() : '' )
				);
			}
		);

		update_option( 'permalink_structure', $previous_permalink_structure );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_init_still_wires_the_webhook_registration_coordinator_when_wp_rewrite_is_missing(): void {
		$previous_permalink_structure = get_option( 'permalink_structure' );
		update_option( 'permalink_structure', '/%postname%/' );

		$this->with_a_fresh_unbooted_plugin_instance(
			function () {
				$previous_wp_rewrite = $GLOBALS['wp_rewrite'] ?? null;
				unset( $GLOBALS['wp_rewrite'] );

				try {
					$plugin = Plugin::instance();
					$plugin->init();
				} finally {
					$GLOBALS['wp_rewrite'] = $previous_wp_rewrite;
				}

				$this->assertNotNull( $plugin->webhook_registration_coordinator() );
			}
		);

		update_option( 'permalink_structure', $previous_permalink_structure );
	}
}
