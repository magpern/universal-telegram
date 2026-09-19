<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integrations\FluentContactInbox\Digest;

use PHPUnit\Framework\TestCase;
use UniversalTelegram\Integrations\FluentContactInbox\Digest\TicketDigestSettings;

final class TicketDigestSettingsTest extends TestCase {

	public function test_defaults_are_disabled_daily_nine(): void {
		$this->assertSame(
			array(
				'enabled'        => false,
				'bot_id'         => null,
				'destination_id' => null,
				'interval'       => 'daily',
				'time'           => '09:00',
			),
			( new TicketDigestSettings( static fn () => array() ) )->get()
		);
	}

	public function test_sanitize_normalises_and_rejects_bad_input(): void {
		$this->assertSame(
			array(
				'enabled'        => true,
				'bot_id'         => 3,
				'destination_id' => 9,
				'interval'       => 'weekly',
				'time'           => '07:45',
			),
			TicketDigestSettings::sanitize(
				array(
					'enabled'        => '1',
					'bot_id'         => '3',
					'destination_id' => '9',
					'interval'       => 'weekly',
					'time'           => '07:45',
				)
			)
		);
		$this->assertSame(
			array(
				'enabled'        => false,
				'bot_id'         => null,
				'destination_id' => null,
				'interval'       => 'daily',
				'time'           => '09:00',
			),
			TicketDigestSettings::sanitize(
				array(
					'bot_id'         => '0',
					'destination_id' => 'x',
					'interval'       => 'hourly',
					'time'           => '25:99',
				)
			)
		);
	}

	public function test_save_persists_the_sanitized_value(): void {
		$stored   = null;
		$settings = new TicketDigestSettings(
			static fn () => array(),
			static function ( $value ) use ( &$stored ) {
				$stored = $value;
			}
		);

		$result = $settings->save(
			array(
				'enabled'        => 1,
				'bot_id'         => 2,
				'destination_id' => 5,
				'time'           => 'nonsense',
			)
		);

		$this->assertSame( $result, $stored );
		$this->assertSame( '09:00', $stored['time'] );
	}
}
