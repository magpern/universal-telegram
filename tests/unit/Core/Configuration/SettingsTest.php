<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Core\Configuration;

use PHPUnit\Framework\TestCase;
use UniversalTelegram\Core\Configuration\Settings;

final class SettingsTest extends TestCase {

	public function test_sanitize_returns_the_array_input_unchanged(): void {
		$settings = new Settings();
		$input    = array( 'example' => 'value' );

		$this->assertSame( $input, $settings->sanitize( $input ) );
	}

	public function test_sanitize_falls_back_to_defaults_for_non_array_input(): void {
		$settings = new Settings();

		$this->assertSame( $settings->defaults(), $settings->sanitize( 'not-an-array' ) );
		$this->assertSame( $settings->defaults(), $settings->sanitize( null ) );
	}

	public function test_defaults_is_an_empty_array_at_m00(): void {
		$settings = new Settings();

		$this->assertSame( array(), $settings->defaults() );
	}
}
