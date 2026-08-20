<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Core\Configuration;

use PHPUnit\Framework\TestCase;
use UniversalTelegram\Core\Configuration\Settings;

final class SettingsTest extends TestCase {

	public function test_sanitize_recognizes_remove_data_on_uninstall(): void {
		$settings = new Settings();

		$this->assertTrue( $settings->sanitize( array( 'remove_data_on_uninstall' => true ) )['remove_data_on_uninstall'] );
		$this->assertFalse( $settings->sanitize( array( 'remove_data_on_uninstall' => '' ) )['remove_data_on_uninstall'] );
	}

	public function test_sanitize_ignores_unknown_fields(): void {
		$settings = new Settings();

		$this->assertSame( $settings->defaults(), $settings->sanitize( array( 'unknown_field' => 'value' ) ) );
	}

	public function test_sanitize_falls_back_to_defaults_for_non_array_input(): void {
		$settings = new Settings();

		$this->assertSame( $settings->defaults(), $settings->sanitize( 'not-an-array' ) );
		$this->assertSame( $settings->defaults(), $settings->sanitize( null ) );
	}

	public function test_defaults_disables_data_removal_on_uninstall(): void {
		$settings = new Settings();

		$this->assertFalse( $settings->defaults()['remove_data_on_uninstall'] );
	}

	public function test_defaults_include_the_frozen_m01_numeric_defaults(): void {
		$settings = new Settings();
		$defaults = $settings->defaults();

		$this->assertSame( 30, $defaults['telegram_message_retention_days'] );
		$this->assertSame( 90, $defaults['telegram_delivery_log_retention_days'] );
		$this->assertSame( 86400, $defaults['telegram_max_pending_seconds'] );
		$this->assertSame( 1048576, $defaults['telegram_webhook_max_body_bytes'] );
		$this->assertSame( 1800, $defaults['telegram_stale_pending_alert_seconds'] );
		$this->assertSame( 30, $defaults['telegram_rate_limit_fallback_wait_seconds'] );
		$this->assertSame( 24, $defaults['telegram_webhook_rotation_max_pending_hours'] );
	}

	/**
	 * @dataProvider positive_integer_field_provider
	 */
	public function test_sanitize_accepts_a_valid_positive_integer_override( string $field ): void {
		$settings = new Settings();

		$this->assertSame( 5, $settings->sanitize( array( $field => 5 ) )[ $field ] );
		$this->assertSame( 5, $settings->sanitize( array( $field => '5' ) )[ $field ] );
	}

	/**
	 * @dataProvider positive_integer_field_provider
	 */
	public function test_sanitize_falls_back_to_default_for_a_non_positive_or_non_numeric_value( string $field ): void {
		$settings = new Settings();
		$default  = $settings->defaults()[ $field ];

		$this->assertSame( $default, $settings->sanitize( array( $field => 0 ) )[ $field ] );
		$this->assertSame( $default, $settings->sanitize( array( $field => -1 ) )[ $field ] );
		$this->assertSame( $default, $settings->sanitize( array( $field => 'not-a-number' ) )[ $field ] );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function positive_integer_field_provider(): array {
		return array(
			'telegram_message_retention_days'             => array( 'telegram_message_retention_days' ),
			'telegram_delivery_log_retention_days'        => array( 'telegram_delivery_log_retention_days' ),
			'telegram_max_pending_seconds'                => array( 'telegram_max_pending_seconds' ),
			'telegram_webhook_max_body_bytes'             => array( 'telegram_webhook_max_body_bytes' ),
			'telegram_stale_pending_alert_seconds'        => array( 'telegram_stale_pending_alert_seconds' ),
			'telegram_rate_limit_fallback_wait_seconds'   => array( 'telegram_rate_limit_fallback_wait_seconds' ),
			'telegram_webhook_rotation_max_pending_hours' => array( 'telegram_webhook_rotation_max_pending_hours' ),
		);
	}
}
