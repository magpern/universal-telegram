<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integrations\FluentContactInbox\Digest;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use UniversalTelegram\Integrations\FluentContactInbox\Digest\DigestSchedule;

final class DigestScheduleTest extends TestCase {

	private function ts( string $local, string $tz ): int {
		return ( new DateTimeImmutable( $local, new DateTimeZone( $tz ) ) )->getTimestamp();
	}

	private function local( int $ts, string $tz ): string {
		return ( new DateTimeImmutable( '@' . $ts ) )->setTimezone( new DateTimeZone( $tz ) )->format( 'Y-m-d H:i T' );
	}

	public function test_daily_picks_later_today_when_the_time_has_not_passed(): void {
		$tz   = 'Europe/Stockholm';
		$next = DigestSchedule::next_occurrence( $this->ts( '2026-06-10 08:00', $tz ), 'daily', '09:00', new DateTimeZone( $tz ) );

		$this->assertSame( '2026-06-10 09:00 CEST', $this->local( $next, $tz ) );
	}

	public function test_daily_rolls_to_tomorrow_once_the_time_has_passed_and_is_strictly_in_the_future(): void {
		$tz = 'Europe/Stockholm';

		$this->assertSame(
			'2026-06-11 09:00 CEST',
			$this->local( DigestSchedule::next_occurrence( $this->ts( '2026-06-10 09:30', $tz ), 'daily', '09:00', new DateTimeZone( $tz ) ), $tz )
		);
		$this->assertSame(
			'2026-06-11 09:00 CEST',
			$this->local( DigestSchedule::next_occurrence( $this->ts( '2026-06-10 09:00', $tz ), 'daily', '09:00', new DateTimeZone( $tz ) ), $tz )
		);
	}

	public function test_daily_stays_at_local_nine_across_the_spring_forward_transition(): void {
		$tz   = 'Europe/Stockholm';
		$now  = $this->ts( '2026-03-28 10:00', $tz );
		$next = DigestSchedule::next_occurrence( $now, 'daily', '09:00', new DateTimeZone( $tz ) );

		$this->assertSame( '2026-03-29 09:00 CEST', $this->local( $next, $tz ) );
		// 10:00 CET -> 09:00 CEST is 22 h of real time; a fixed 24 h interval would have landed at 10:00 CEST.
		$this->assertSame( 22 * 3600, $next - $now );
	}

	public function test_daily_stays_at_local_nine_across_the_fall_back_transition(): void {
		$tz   = 'Europe/Stockholm';
		$next = DigestSchedule::next_occurrence( $this->ts( '2026-10-24 10:00', $tz ), 'daily', '09:00', new DateTimeZone( $tz ) );

		$this->assertSame( '2026-10-25 09:00 CET', $this->local( $next, $tz ) );
	}

	public function test_a_local_time_that_does_not_exist_is_recomputed_fresh_each_time_and_does_not_drift(): void {
		$tz  = new DateTimeZone( 'Europe/Stockholm' );
		$dst = DigestSchedule::next_occurrence( $this->ts( '2026-03-28 10:00', 'Europe/Stockholm' ), 'daily', '02:30', $tz );

		// 02:30 does not exist on 2026-03-29; the chain must be back on 02:30 the day after.
		$after = DigestSchedule::next_occurrence( $dst, 'daily', '02:30', $tz );

		$this->assertSame( '2026-03-30 02:30 CEST', $this->local( $after, 'Europe/Stockholm' ) );
	}

	public function test_weekly_lands_on_the_coming_monday_at_the_local_time(): void {
		$tz   = 'Europe/Stockholm';
		$next = DigestSchedule::next_occurrence( $this->ts( '2026-09-16 12:00', $tz ), 'weekly', '08:15', new DateTimeZone( $tz ) ); // Wednesday.

		$this->assertSame( '2026-09-21 08:15 CEST', $this->local( $next, $tz ) );
	}

	public function test_weekly_on_a_monday_before_the_time_is_that_same_day(): void {
		$tz   = 'Europe/Stockholm';
		$next = DigestSchedule::next_occurrence( $this->ts( '2026-09-21 07:00', $tz ), 'weekly', '08:15', new DateTimeZone( $tz ) );

		$this->assertSame( '2026-09-21 08:15 CEST', $this->local( $next, $tz ) );
	}

	public function test_weekly_crossing_the_fall_back_transition_keeps_the_local_time(): void {
		$tz   = 'Europe/Stockholm';
		$next = DigestSchedule::next_occurrence( $this->ts( '2026-10-20 12:00', $tz ), 'weekly', '09:00', new DateTimeZone( $tz ) );

		$this->assertSame( '2026-10-26 09:00 CET', $this->local( $next, $tz ) );
	}
}
