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

	public function test_defaults_disables_the_chat_widget(): void {
		$settings = new Settings();

		$this->assertFalse( $settings->defaults()['chat_widget_enabled'] );
	}

	public function test_sanitize_recognizes_chat_widget_enabled(): void {
		$settings = new Settings();

		$this->assertTrue( $settings->sanitize( array( 'chat_widget_enabled' => true ) )['chat_widget_enabled'] );
		$this->assertFalse( $settings->sanitize( array( 'chat_widget_enabled' => '' ) )['chat_widget_enabled'] );
	}

	public function test_defaults_include_the_m06_3_presentation_fields(): void {
		$settings = new Settings();
		$defaults = $settings->defaults();

		$this->assertSame( 'modern', $defaults['chat_widget_preset'] );
		$this->assertSame( 'round', $defaults['chat_widget_geometry'] );
		$this->assertSame( 'standard', $defaults['chat_widget_motion_default'] );
		$this->assertSame( 'You', $defaults['chat_widget_participant_label_visitor'] );
		$this->assertSame( 'Support', $defaults['chat_widget_participant_label_operator'] );
	}

	public function test_sanitize_recognizes_a_valid_chat_widget_preset(): void {
		$settings = new Settings();

		$this->assertSame( 'classic', $settings->sanitize( array( 'chat_widget_preset' => 'classic' ) )['chat_widget_preset'] );
		$this->assertSame( 'minimal', $settings->sanitize( array( 'chat_widget_preset' => 'minimal' ) )['chat_widget_preset'] );
	}

	public function test_sanitize_falls_back_to_the_default_preset_for_an_unrecognized_value(): void {
		$settings = new Settings();

		$this->assertSame( 'modern', $settings->sanitize( array( 'chat_widget_preset' => 'made-up' ) )['chat_widget_preset'] );
	}

	public function test_sanitize_recognizes_a_valid_geometry(): void {
		$settings = new Settings();

		$this->assertSame( 'square', $settings->sanitize( array( 'chat_widget_geometry' => 'square' ) )['chat_widget_geometry'] );
	}

	public function test_sanitize_falls_back_to_the_default_geometry_for_an_unrecognized_value(): void {
		$settings = new Settings();

		$this->assertSame( 'round', $settings->sanitize( array( 'chat_widget_geometry' => 'triangular' ) )['chat_widget_geometry'] );
	}

	public function test_sanitize_recognizes_a_valid_motion_default(): void {
		$settings = new Settings();

		$this->assertSame( 'reduced', $settings->sanitize( array( 'chat_widget_motion_default' => 'reduced' ) )['chat_widget_motion_default'] );
	}

	public function test_sanitize_falls_back_to_the_default_motion_for_an_unrecognized_value(): void {
		$settings = new Settings();

		$this->assertSame( 'standard', $settings->sanitize( array( 'chat_widget_motion_default' => 'wild' ) )['chat_widget_motion_default'] );
	}

	/**
	 * @dataProvider participant_label_field_provider
	 */
	public function test_sanitize_trims_and_accepts_a_valid_participant_label( string $field, string $default ): void {
		$settings = new Settings();

		$this->assertSame( 'Hi there', $settings->sanitize( array( $field => '  Hi there  ' ) )[ $field ] );
	}

	/**
	 * @dataProvider participant_label_field_provider
	 */
	public function test_sanitize_falls_back_to_default_for_an_empty_or_oversized_participant_label( string $field, string $default ): void {
		$settings = new Settings();

		$this->assertSame( $default, $settings->sanitize( array( $field => '   ' ) )[ $field ] );
		$this->assertSame( $default, $settings->sanitize( array( $field => str_repeat( 'a', 41 ) ) )[ $field ] );
	}

	/**
	 * @return array<int, array{0: string, 1: string}>
	 */
	public function participant_label_field_provider(): array {
		return array(
			array( 'chat_widget_participant_label_visitor', 'You' ),
			array( 'chat_widget_participant_label_operator', 'Support' ),
		);
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

	public function test_defaults_include_the_frozen_m02_retention_defaults(): void {
		$settings = new Settings();
		$defaults = $settings->defaults();

		$this->assertSame( 90, $defaults['event_retention_days'] );
		$this->assertSame( 90, $defaults['dispatch_log_retention_days'] );
		$this->assertSame( 30, $defaults['fatal_marker_retention_days'] );
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
			'event_retention_days'                        => array( 'event_retention_days' ),
			'dispatch_log_retention_days'                 => array( 'dispatch_log_retention_days' ),
			'fatal_marker_retention_days'                 => array( 'fatal_marker_retention_days' ),
		);
	}

	public function test_visitor_tracking_and_every_family_toggle_default_off(): void {
		$defaults = ( new Settings() )->defaults();

		$this->assertFalse( $defaults['visitor_tracking_enabled'] );
		$this->assertFalse( $defaults['visitor_family_page_views'] );
		$this->assertFalse( $defaults['visitor_family_navigation'] );
		$this->assertFalse( $defaults['visitor_family_search'] );
		$this->assertFalse( $defaults['visitor_family_clicks'] );
		$this->assertFalse( $defaults['visitor_family_errors'] );
		$this->assertFalse( $defaults['visitor_family_commerce'] );
		$this->assertSame( 'required', $defaults['visitor_consent_mode'] );
		$this->assertSame( 100, $defaults['visitor_sampling_percent'] );
		$this->assertSame( array(), $defaults['visitor_click_target_allowlist'] );
		$this->assertTrue( $defaults['visitor_exclude_administrators'] );
	}

	/**
	 * @dataProvider visitor_boolean_field_provider
	 */
	public function test_sanitize_accepts_a_visitor_boolean_override( string $field ): void {
		$settings = new Settings();

		$this->assertTrue( $settings->sanitize( array( $field => true ) )[ $field ] );
		$this->assertFalse( $settings->sanitize( array( $field => false ) )[ $field ] );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function visitor_boolean_field_provider(): array {
		return array(
			'visitor_tracking_enabled'       => array( 'visitor_tracking_enabled' ),
			'visitor_family_page_views'      => array( 'visitor_family_page_views' ),
			'visitor_family_navigation'      => array( 'visitor_family_navigation' ),
			'visitor_family_search'          => array( 'visitor_family_search' ),
			'visitor_family_clicks'          => array( 'visitor_family_clicks' ),
			'visitor_family_errors'          => array( 'visitor_family_errors' ),
			'visitor_family_commerce'        => array( 'visitor_family_commerce' ),
			'visitor_exclude_administrators' => array( 'visitor_exclude_administrators' ),
		);
	}

	public function test_sanitize_rejects_an_unrecognized_consent_mode(): void {
		$settings = new Settings();

		$this->assertSame( 'required', $settings->sanitize( array( 'visitor_consent_mode' => 'sort-of' ) )['visitor_consent_mode'] );
		$this->assertSame( 'disabled', $settings->sanitize( array( 'visitor_consent_mode' => 'disabled' ) )['visitor_consent_mode'] );
	}

	public function test_sanitize_clamps_sampling_percent_to_1_100(): void {
		$settings = new Settings();

		$this->assertSame( 1, $settings->sanitize( array( 'visitor_sampling_percent' => 0 ) )['visitor_sampling_percent'] );
		$this->assertSame( 100, $settings->sanitize( array( 'visitor_sampling_percent' => 500 ) )['visitor_sampling_percent'] );
		$this->assertSame( 42, $settings->sanitize( array( 'visitor_sampling_percent' => 42 ) )['visitor_sampling_percent'] );
	}

	public function test_sanitize_bounds_the_click_target_allowlist_to_eight_sanitized_keys(): void {
		$settings = new Settings();
		$input    = array_map(
			static function ( int $i ): string {
				return "key-$i";
			},
			range( 1, 12 )
		);

		$result = $settings->sanitize( array( 'visitor_click_target_allowlist' => $input ) )['visitor_click_target_allowlist'];

		$this->assertCount( 8, $result );
	}
}
