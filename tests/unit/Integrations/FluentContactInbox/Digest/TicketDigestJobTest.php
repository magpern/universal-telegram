<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integrations\FluentContactInbox\Digest;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use UniversalTelegram\Integrations\FluentContactInbox\Digest\TicketDigestJob;
use UniversalTelegram\Integrations\FluentContactInbox\Digest\TicketDigestSettings;
use UniversalTelegram\Integrations\FluentContactInbox\SupportTicket;
use UniversalTelegram\Tests\Integrations\FluentContactInbox\Support\FakeDesk;
use UniversalTelegram\Tests\Integrations\FluentContactInbox\Support\FakeDigestScheduler;
use UniversalTelegram\Tests\Integrations\FluentContactInbox\Support\FakeDigestSender;

final class TicketDigestJobTest extends TestCase {

	private FakeDigestScheduler $scheduler;
	private FakeDigestSender $sender;
	private FakeDesk $desk;
	private int $now;

	/** @var array<string, mixed> */
	private array $config;

	protected function setUp(): void {
		$this->scheduler = new FakeDigestScheduler();
		$this->sender    = new FakeDigestSender();
		$this->desk      = new FakeDesk();
		$this->now       = ( new DateTimeImmutable( '2026-09-19 08:00:00', new DateTimeZone( 'Europe/Stockholm' ) ) )->getTimestamp();
		$this->config    = array(
			'enabled'        => true,
			'bot_id'         => 4,
			'destination_id' => 8,
			'interval'       => 'daily',
			'time'           => '09:00',
		);
	}

	private function job(): TicketDigestJob {
		return new TicketDigestJob(
			$this->desk,
			new TicketDigestSettings( fn () => $this->config ),
			$this->sender,
			$this->scheduler,
			fn () => $this->now,
			static fn () => new DateTimeZone( 'Europe/Stockholm' )
		);
	}

	private function nine(): int {
		return ( new DateTimeImmutable( '2026-09-19 09:00:00', new DateTimeZone( 'Europe/Stockholm' ) ) )->getTimestamp();
	}

	public function test_ensure_scheduled_creates_exactly_one_occurrence_and_is_idempotent(): void {
		$job = $this->job();

		$job->ensure_scheduled();
		$job->ensure_scheduled();
		$job->ensure_scheduled();

		$this->assertSame( array( $this->nine() ), $this->scheduler->pending );
	}

	public function test_ensure_scheduled_clears_a_stale_chain_when_disabled(): void {
		$this->scheduler->pending = array( 123 );
		$this->config['enabled']  = false;

		$this->job()->ensure_scheduled();

		$this->assertSame( array(), $this->scheduler->pending );
	}

	public function test_incomplete_configuration_schedules_nothing(): void {
		$this->config['destination_id'] = null;

		$this->job()->ensure_scheduled();

		$this->assertSame( array(), $this->scheduler->pending );
	}

	public function test_reschedule_replaces_the_old_occurrence_with_one_from_the_new_settings(): void {
		$job = $this->job();
		$job->ensure_scheduled();

		$this->config['time'] = '11:30';
		$job->reschedule();

		$expected = ( new DateTimeImmutable( '2026-09-19 11:30:00', new DateTimeZone( 'Europe/Stockholm' ) ) )->getTimestamp();
		$this->assertSame( array( $expected ), $this->scheduler->pending );
	}

	public function test_run_clears_other_pending_occurrences_and_leaves_exactly_one_next(): void {
		$this->scheduler->pending = array( 111, 222, 333 );
		$this->now                = $this->nine();

		$this->job()->run();

		$this->assertCount( 1, $this->scheduler->pending );
		$this->assertSame( $this->nine() + 86400, $this->scheduler->pending[0] );
	}

	public function test_run_sends_counts_to_the_configured_target(): void {
		$this->desk->open    = 5;
		$this->desk->pending = 2;
		$this->desk->oldest  = array( new SupportTicket( 1, 1042, 'Hello', 'a@b.se', 'Jane', 'open', false, '2026-09-16 12:00:00' ) );

		$this->job()->run();

		$this->assertCount( 1, $this->sender->sent );
		list( $bot, $destination, $text ) = $this->sender->sent[0];
		$this->assertSame( 4, $bot );
		$this->assertSame( 8, $destination );
		$this->assertStringContainsString( 'Unanswered \(open\): 5', $text );
		$this->assertStringContainsString( 'Awaiting customer \(pending\): 2', $text );
	}

	public function test_run_when_disabled_sends_nothing_and_schedules_nothing(): void {
		$this->config['enabled'] = false;

		$this->job()->run();

		$this->assertSame( array(), $this->sender->sent );
		$this->assertSame( array(), $this->scheduler->pending );
	}
}
