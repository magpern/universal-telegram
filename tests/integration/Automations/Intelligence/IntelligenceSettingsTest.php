<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Automations\Intelligence;

use UniversalTelegram\Automations\Intelligence\IntelligenceSettings;
use UniversalTelegram\Core\Configuration\Settings;
use WP_UnitTestCase;

final class IntelligenceSettingsTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( Settings::OPTION_NAME );
	}

	private function subject(): IntelligenceSettings {
		return new IntelligenceSettings( new Settings() );
	}

	public function test_defaults_are_disabled_with_frozen_ranges(): void {
		$subject = $this->subject();

		$this->assertFalse( $subject->operational_summary_enabled() );
		$this->assertNull( $subject->operational_summary_bot_id() );
		$this->assertNull( $subject->operational_summary_destination_id() );
		$this->assertSame( 6, $subject->operational_summary_hour_utc() );
		$this->assertNull( $subject->alert_bot_id() );
		$this->assertNull( $subject->alert_destination_id() );

		foreach ( IntelligenceSettings::ALERT_TYPES as $alert_type ) {
			$this->assertFalse( $subject->alert_enabled( $alert_type ) );
		}

		$this->assertSame( 10, $subject->alert_threshold( 'checkout_failure_count' ) );
		$this->assertSame( 10, $subject->alert_threshold( 'order_failure_spike' ) );
		$this->assertSame( 50, $subject->alert_threshold( 'js_error_spike' ) );
	}

	public function test_reads_saved_values(): void {
		update_option(
			Settings::OPTION_NAME,
			( new Settings() )->sanitize(
				array(
					'operational_summary_enabled'            => true,
					'operational_summary_bot_id'              => 7,
					'operational_summary_destination_id'      => 9,
					'operational_summary_hour_utc'             => 3,
					'alert_bot_id'                              => 5,
					'alert_destination_id'                      => 11,
					'alert_checkout_failure_count_enabled'    => true,
					'alert_checkout_failure_count_threshold'  => 25,
				)
			)
		);

		$subject = $this->subject();

		$this->assertTrue( $subject->operational_summary_enabled() );
		$this->assertSame( 7, $subject->operational_summary_bot_id() );
		$this->assertSame( 9, $subject->operational_summary_destination_id() );
		$this->assertSame( 3, $subject->operational_summary_hour_utc() );
		$this->assertSame( 5, $subject->alert_bot_id() );
		$this->assertSame( 11, $subject->alert_destination_id() );
		$this->assertTrue( $subject->alert_enabled( 'checkout_failure_count' ) );
		$this->assertSame( 25, $subject->alert_threshold( 'checkout_failure_count' ) );
	}

	public function test_unknown_alert_type_is_disabled_and_zero_threshold(): void {
		$subject = $this->subject();

		$this->assertFalse( $subject->alert_enabled( 'not_a_real_alert' ) );
		$this->assertSame( 0, $subject->alert_threshold( 'not_a_real_alert' ) );
	}
}
