<?php
/**
 * Pure next-occurrence calculation for the ticket digest.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Integrations\FluentContactInbox\Digest;

use DateTimeImmutable;
use DateTimeZone;

/**
 * "Daily/weekly at HH:MM" is a wall-clock time in the WordPress site
 * timezone, so the next occurrence is recomputed from the calendar every
 * time (never `now + 86400`), which keeps it correct across DST changes
 * (docs/adr/0046). Weekly digests are sent on Mondays.
 */
final class DigestSchedule {

	public const INTERVAL_DAILY  = 'daily';
	public const INTERVAL_WEEKLY = 'weekly';

	/**
	 * The UTC timestamp of the first occurrence strictly after `$now`.
	 *
	 * @param int          $now      The current UTC timestamp.
	 * @param string       $interval `daily` or `weekly`.
	 * @param string       $time     Local time of day, `HH:MM`.
	 * @param DateTimeZone $timezone The site timezone.
	 *
	 * @return int
	 */
	public static function next_occurrence( int $now, string $interval, string $time, DateTimeZone $timezone ): int {
		list( $hour, $minute ) = array_map( 'intval', explode( ':', $time ) );

		$local     = ( new DateTimeImmutable( '@' . $now ) )->setTimezone( $timezone );
		$candidate = $local->setTime( $hour, $minute, 0 );

		if ( self::INTERVAL_WEEKLY === $interval ) {
			$candidate = $candidate->modify( '-' . ( (int) $candidate->format( 'N' ) - 1 ) . ' days' )->setTime( $hour, $minute, 0 );
			$step      = '+7 days';
		} else {
			$step = '+1 day';
		}

		while ( $candidate->getTimestamp() <= $now ) {
			$candidate = $candidate->modify( $step )->setTime( $hour, $minute, 0 );
		}

		return $candidate->getTimestamp();
	}
}
