<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Events\Visitor;

use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;
use UniversalTelegram\Core\Configuration\Settings;
use UniversalTelegram\Events\Visitor\PageContext;
use UniversalTelegram\Events\Visitor\TrackerAssets;
use UniversalTelegram\Integrations\WooCommerce\WooCommerceSupport;
use WP_UnitTestCase;

final class TrackerAssetsTest extends WP_UnitTestCase {

	protected function tearDown(): void {
		wp_deregister_script( 'universal-telegram-visitor-tracker' );
		parent::tearDown();
	}

	private function tracker_assets(): TrackerAssets {
		return new TrackerAssets( new Settings(), new PageContext(), new WooCommerceSupport(), new CapabilityRegistrar() );
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

	public function test_the_tracker_is_not_enqueued_in_the_admin_context(): void {
		update_option( Settings::OPTION_NAME, array_merge( ( new Settings() )->defaults(), array( 'visitor_tracking_enabled' => true ) ) );

		set_current_screen( 'dashboard' );
		$this->tracker_assets()->enqueue();
		set_current_screen( 'front' );

		$this->assertFalse( wp_script_is( 'universal-telegram-visitor-tracker', 'enqueued' ) );
	}

	public function test_inline_config_is_static_per_url_and_carries_the_current_page_context(): void {
		update_option( Settings::OPTION_NAME, array_merge( ( new Settings() )->defaults(), array( 'visitor_tracking_enabled' => true ) ) );

		$this->go_to( home_url( '/' ) );
		$this->tracker_assets()->enqueue();

		global $wp_scripts;
		$data = $wp_scripts->get_data( 'universal-telegram-visitor-tracker', 'before' );

		$this->assertNotEmpty( $data );
		$this->assertStringContainsString( 'UniversalTelegramVisitorConfig', $data[0] );
		$this->assertStringContainsString( '"initialPageType":"home"', $data[0] );
	}
}
