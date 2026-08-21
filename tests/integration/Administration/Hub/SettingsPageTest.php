<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Administration\Hub;

use UniversalTelegram\Administration\Hub\SettingsPage;
use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;
use UniversalTelegram\Core\Configuration\Settings;
use WP_UnitTestCase;

final class SettingsPageTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();

		// The test bootstrap loads the plugin as an MU-plugin, bypassing
		// WordPress' real activation flow, so the capability Activator
		// would normally grant is never actually granted here.
		( new CapabilityRegistrar() )->grant_to_administrator();
	}

	private function page(): SettingsPage {
		return new SettingsPage( new Settings() );
	}

	private function saving_page(): SettingsPage {
		return new class( new Settings() ) extends SettingsPage {
			public ?string $redirected_to = null;

			protected function redirect_and_exit( string $url ): void {
				$this->redirected_to = $url;
			}
		};
	}

	public function test_a_user_lacking_the_capability_is_denied_by_wordpress_itself(): void {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$this->expectException( \WPDieException::class );
		$this->page()->render_tab_content();
	}

	public function test_an_administrator_can_render_the_page(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		ob_start();
		$this->page()->render_tab_content();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Remove all plugin data on uninstall', $output );
		$this->assertStringContainsString( 'universal_telegram_settings[event_retention_days]', $output );
		$this->assertStringContainsString( 'universal_telegram_settings[chat_widget_enabled]', $output );
		$this->assertStringContainsString( 'Enable chat widget', $output );
	}

	public function test_handle_request_denies_a_user_lacking_the_capability(): void {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$this->expectException( \WPDieException::class );
		$this->page()->handle_request();
	}

	public function test_every_integer_field_round_trips_through_the_existing_sanitizer(): void {
		$settings  = new Settings();
		$sanitized = $settings->sanitize(
			array_merge(
				$settings->defaults(),
				array(
					'remove_data_on_uninstall'             => true,
					'telegram_message_retention_days'      => 45,
					'telegram_delivery_log_retention_days' => 120,
					'telegram_max_pending_seconds'         => 7200,
					'telegram_webhook_max_body_bytes'      => 2097152,
					'telegram_stale_pending_alert_seconds' => 900,
					'telegram_rate_limit_fallback_wait_seconds' => 60,
					'telegram_webhook_rotation_max_pending_hours' => 12,
					'event_retention_days'                 => 180,
					'dispatch_log_retention_days'          => 180,
					'fatal_marker_retention_days'          => 14,
				)
			)
		);

		$this->assertTrue( $sanitized['remove_data_on_uninstall'] );
		$this->assertSame( 45, $sanitized['telegram_message_retention_days'] );
		$this->assertSame( 120, $sanitized['telegram_delivery_log_retention_days'] );
		$this->assertSame( 7200, $sanitized['telegram_max_pending_seconds'] );
		$this->assertSame( 2097152, $sanitized['telegram_webhook_max_body_bytes'] );
		$this->assertSame( 900, $sanitized['telegram_stale_pending_alert_seconds'] );
		$this->assertSame( 60, $sanitized['telegram_rate_limit_fallback_wait_seconds'] );
		$this->assertSame( 12, $sanitized['telegram_webhook_rotation_max_pending_hours'] );
		$this->assertSame( 180, $sanitized['event_retention_days'] );
		$this->assertSame( 180, $sanitized['dispatch_log_retention_days'] );
		$this->assertSame( 14, $sanitized['fatal_marker_retention_days'] );
	}

	public function test_unchecking_the_chat_widget_box_and_saving_actually_disables_it(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		update_option( Settings::OPTION_NAME, ( new Settings() )->sanitize( array( 'chat_widget_enabled' => true ) ) );

		$_POST['universal_telegram_settings'] = array(
			'event_retention_days' => '90',
		);
		$nonce                                = wp_create_nonce( SettingsPage::NONCE_ACTION );
		$_POST['_wpnonce']                    = $nonce;
		$_REQUEST['_wpnonce']                 = $nonce;

		$this->saving_page()->handle_request();

		$this->assertFalse( ( new Settings() )->get()['chat_widget_enabled'] );

		unset( $_POST['universal_telegram_settings'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );
	}
}
