<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Events\Visitor;

use UniversalTelegram\Core\Configuration\Settings;
use UniversalTelegram\Events\Visitor\PageContext;
use UniversalTelegram\Events\Visitor\TrackerAssets;
use UniversalTelegram\Integrations\WooCommerce\WooCommerceSupport;
use WP_UnitTestCase;

final class TrackerAssetsTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();

		// $wp_scripts is a global singleton WordPress core's own test
		// harness does not reset between tests; forcing a fresh instance
		// avoids inline-script data from an earlier test leaking onto the
		// same handle in a later one.
		global $wp_scripts;
		$wp_scripts = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}

	protected function tearDown(): void {
		wp_deregister_script( 'universal-telegram-visitor-tracker' );
		parent::tearDown();
	}

	private function tracker_assets(): TrackerAssets {
		return new TrackerAssets( new Settings(), new PageContext(), new WooCommerceSupport() );
	}

	public function test_the_tracker_is_not_enqueued_when_tracking_is_disabled(): void {
		update_option( Settings::OPTION_NAME, array_merge( ( new Settings() )->defaults(), array( 'visitor_tracking_enabled' => false ) ) );

		$this->go_to( home_url( '/' ) );
		$this->tracker_assets()->enqueue();

		$this->assertFalse( wp_script_is( 'universal-telegram-visitor-tracker', 'enqueued' ) );
	}

	public function test_the_tracker_is_enqueued_on_the_frontend_when_tracking_is_enabled(): void {
		update_option( Settings::OPTION_NAME, array_merge( ( new Settings() )->defaults(), array( 'visitor_tracking_enabled' => true ) ) );

		$this->go_to( home_url( '/' ) );
		$this->tracker_assets()->enqueue();

		$this->assertTrue( wp_script_is( 'universal-telegram-visitor-tracker', 'enqueued' ) );
	}

	public function test_inline_config_is_static_per_url_and_carries_the_current_page_context(): void {
		update_option( Settings::OPTION_NAME, array_merge( ( new Settings() )->defaults(), array( 'visitor_tracking_enabled' => true ) ) );

		$this->go_to( home_url( '/' ) );
		$this->tracker_assets()->enqueue();

		global $wp_scripts;
		$data = $wp_scripts->get_data( 'universal-telegram-visitor-tracker', 'before' );

		$this->assertNotEmpty( $data );
		// WP_Scripts::add_inline_script() casts get_data()'s initial
		// `false` return to an array on the first call for any handle,
		// which becomes a leading `false` element — the actual inline
		// script is always the last entry, never necessarily index 0.
		$inline_script = end( $data );
		$this->assertStringContainsString( 'UniversalTelegramVisitorConfig', $inline_script );
		$this->assertStringContainsString( '"initialPageType":"home"', $inline_script );
	}

	/**
	 * Runs last in this class: REST_REQUEST is a real PHP constant and
	 * cannot be undefined once set, so this exclusion check is exercised
	 * only after every other assertion in this file no longer needs a
	 * non-REST request context.
	 */
	public function test_z_the_tracker_is_not_enqueued_during_a_rest_request(): void {
		update_option( Settings::OPTION_NAME, array_merge( ( new Settings() )->defaults(), array( 'visitor_tracking_enabled' => true ) ) );

		$this->go_to( home_url( '/' ) );

		if ( ! defined( 'REST_REQUEST' ) ) {
			define( 'REST_REQUEST', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
		}

		$this->tracker_assets()->enqueue();

		$this->assertFalse( wp_script_is( 'universal-telegram-visitor-tracker', 'enqueued' ) );
	}
}
