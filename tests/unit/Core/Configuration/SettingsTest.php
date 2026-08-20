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

		$this->assertSame(
			array( 'remove_data_on_uninstall' => true ),
			$settings->sanitize( array( 'remove_data_on_uninstall' => true ) )
		);
		$this->assertSame(
			array( 'remove_data_on_uninstall' => false ),
			$settings->sanitize( array( 'remove_data_on_uninstall' => '' ) )
		);
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

		$this->assertSame( array( 'remove_data_on_uninstall' => false ), $settings->defaults() );
	}
}
