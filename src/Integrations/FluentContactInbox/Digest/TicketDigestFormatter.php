<?php
/**
 * MarkdownV2 composition of the ticket digest.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Integrations\FluentContactInbox\Digest;

use DateTimeImmutable;
use DateTimeZone;
use UniversalTelegram\Automations\MarkdownV2Escaper;
use UniversalTelegram\Integrations\FluentContactInbox\SupportTicket;

/**
 * Pure. Ticket subjects and names are untrusted, so every dynamic value is
 * escaped with the shared MarkdownV2 escaper, lines are truncated, the list
 * is capped, and the whole text is kept safely below Telegram's 4096
 * character limit (docs/adr/0046).
 */
final class TicketDigestFormatter {

	public const MAX_ITEMS       = 20;
	public const MAX_TOTAL_CHARS = 3500;
	private const MAX_SUBJECT    = 60;
	private const MAX_NAME       = 30;

	/**
	 * Builds the digest text.
	 *
	 * @param int                       $open_count    Non-archived tickets in `open`.
	 * @param int                       $pending_count Non-archived tickets in `pending`.
	 * @param array<int, SupportTicket> $oldest_open   The oldest open tickets, oldest first.
	 * @param int                       $now           Current UTC timestamp (for ages).
	 * @param DateTimeZone              $timezone      Site timezone (ticket times are site-local).
	 *
	 * @return string MarkdownV2.
	 */
	public static function format( int $open_count, int $pending_count, array $oldest_open, int $now, DateTimeZone $timezone ): string {
		$e = static fn ( string $value ): string => MarkdownV2Escaper::escape( $value );

		$head  = '*' . $e( 'Support desk digest' ) . "*\n";
		$head .= $e( sprintf( 'Unanswered (open): %d', $open_count ) ) . "\n";
		$head .= $e( sprintf( 'Awaiting customer (pending): %d', $pending_count ) );

		if ( array() === $oldest_open ) {
			return $head;
		}

		$lines     = array();
		$total     = strlen( $head ) + strlen( $e( "\n\nOldest unanswered:" ) );
		$shown     = 0;
		$available = min( count( $oldest_open ), self::MAX_ITEMS );

		foreach ( array_slice( $oldest_open, 0, $available ) as $ticket ) {
			$line = $e( sprintf( '#%d', $ticket->ticket_number ) )
				. ' ' . $e( self::truncate( '' !== $ticket->subject ? $ticket->subject : '(no subject)', self::MAX_SUBJECT ) );

			if ( '' !== $ticket->customer_name ) {
				$line .= ' ' . $e( '— ' . self::truncate( $ticket->customer_name, self::MAX_NAME ) );
			}

			$age = self::age( $ticket->last_message_at, $now, $timezone );

			if ( '' !== $age ) {
				$line .= ' ' . $e( '· ' . $age );
			}

			// Reserve room for the trailing "and N more" line.
			if ( $total + strlen( $line ) + 1 + 60 > self::MAX_TOTAL_CHARS ) {
				break;
			}

			$lines[] = $line;
			$total  += strlen( $line ) + 1;
			++$shown;
		}

		$out = $head . "\n\n" . $e( 'Oldest unanswered:' ) . "\n" . implode( "\n", $lines );

		if ( $open_count > $shown ) {
			$out .= "\n" . $e( sprintf( '… and %d more', $open_count - $shown ) );
		}

		return $out;
	}

	/**
	 * Human age of a site-local MySQL datetime, e.g. `3d`, `5h`, `12m`.
	 *
	 * @param string       $mysql_local Site-local `Y-m-d H:i:s`.
	 * @param int          $now         Current UTC timestamp.
	 * @param DateTimeZone $timezone    Site timezone.
	 *
	 * @return string Empty when unparseable.
	 */
	public static function age( string $mysql_local, int $now, DateTimeZone $timezone ): string {
		$date = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $mysql_local, $timezone );

		if ( false === $date ) {
			return '';
		}

		$seconds = max( 0, $now - $date->getTimestamp() );

		if ( $seconds >= 86400 ) {
			return (int) floor( $seconds / 86400 ) . 'd';
		}

		if ( $seconds >= 3600 ) {
			return (int) floor( $seconds / 3600 ) . 'h';
		}

		return (int) floor( $seconds / 60 ) . 'm';
	}

	/**
	 * Length-caps a value.
	 *
	 * @param string $value Raw value.
	 * @param int    $max   Maximum characters.
	 *
	 * @return string
	 */
	private static function truncate( string $value, int $max ): string {
		$value = trim( (string) preg_replace( '/\s+/u', ' ', $value ) );

		if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
			return mb_strlen( $value ) > $max ? mb_substr( $value, 0, $max - 1 ) . '…' : $value;
		}

		return strlen( $value ) > $max ? substr( $value, 0, $max - 1 ) . '…' : $value;
	}
}
