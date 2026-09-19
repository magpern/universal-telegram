<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integrations\FluentContactInbox\Digest;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use UniversalTelegram\Integrations\FluentContactInbox\Digest\TicketDigestFormatter;
use UniversalTelegram\Integrations\FluentContactInbox\SupportTicket;

final class TicketDigestFormatterTest extends TestCase {

	private function tz(): DateTimeZone {
		return new DateTimeZone( 'Europe/Stockholm' );
	}

	private function now(): int {
		return ( new DateTimeImmutable( '2026-09-19 12:00:00', $this->tz() ) )->getTimestamp();
	}

	private function ticket( int $number, string $subject = 'Hello', string $name = 'Jane', string $when = '2026-09-16 12:00:00' ): SupportTicket {
		return new SupportTicket( $number, $number, $subject, 'a@b.se', $name, 'open', false, $when );
	}

	public function test_headline_counts_open_as_unanswered_and_pending_as_awaiting_customer(): void {
		$text = TicketDigestFormatter::format( 5, 2, array(), $this->now(), $this->tz() );

		$this->assertStringContainsString( 'Unanswered \(open\): 5', $text );
		$this->assertStringContainsString( 'Awaiting customer \(pending\): 2', $text );
		$this->assertStringNotContainsString( 'Oldest unanswered', $text );
	}

	public function test_lists_oldest_tickets_with_escaped_untrusted_text_and_age(): void {
		$text = TicketDigestFormatter::format( 1, 0, array( $this->ticket( 1042, 'Re: order_#5 [urgent]!', 'Jane.D' ) ), $this->now(), $this->tz() );

		$this->assertStringContainsString( 'Oldest unanswered', $text );
		$this->assertStringContainsString( '\#1042 Re: order\_\#5 \[urgent\]\! — Jane\.D · 3d', str_replace( '\—', '—', $text ) );
	}

	public function test_list_is_capped_and_reports_the_remainder(): void {
		$tickets = array();

		for ( $i = 1; $i <= TicketDigestFormatter::MAX_ITEMS; $i++ ) {
			$tickets[] = $this->ticket( $i );
		}

		$text = TicketDigestFormatter::format( 45, 0, $tickets, $this->now(), $this->tz() );

		$this->assertSame( TicketDigestFormatter::MAX_ITEMS, substr_count( $text, '\#' ) );
		$this->assertStringContainsString( 'and 25 more', $text );
	}

	public function test_output_stays_below_the_telegram_limit_even_with_huge_untrusted_subjects(): void {
		$tickets = array();

		for ( $i = 1; $i <= TicketDigestFormatter::MAX_ITEMS; $i++ ) {
			$tickets[] = $this->ticket( $i, str_repeat( '_*[]()~`>#+-=|{}.!', 40 ), str_repeat( '.', 200 ) );
		}

		$text = TicketDigestFormatter::format( 500, 500, $tickets, $this->now(), $this->tz() );

		$this->assertLessThanOrEqual( 4096, strlen( $text ) );
		$this->assertLessThanOrEqual( TicketDigestFormatter::MAX_TOTAL_CHARS, strlen( $text ) );
	}

	public function test_age_formats(): void {
		$now = $this->now();
		$tz  = $this->tz();

		$this->assertSame( '3d', TicketDigestFormatter::age( '2026-09-16 12:00:00', $now, $tz ) );
		$this->assertSame( '5h', TicketDigestFormatter::age( '2026-09-19 07:00:00', $now, $tz ) );
		$this->assertSame( '12m', TicketDigestFormatter::age( '2026-09-19 11:48:00', $now, $tz ) );
		$this->assertSame( '0m', TicketDigestFormatter::age( '2026-09-19 13:00:00', $now, $tz ) );
		$this->assertSame( '', TicketDigestFormatter::age( 'garbage', $now, $tz ) );
	}
}
