<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integrations\FluentContactInbox\Inbound;

use PHPUnit\Framework\TestCase;
use UniversalTelegram\Integrations\FluentContactInbox\Inbound\TicketReplyHandler;
use UniversalTelegram\Integrations\FluentContactInbox\SupportTicket;
use UniversalTelegram\Tests\Integrations\FluentContactInbox\Support\FakeDesk;
use UniversalTelegram\Tests\Integrations\FluentContactInbox\Support\FakeReplyEnvironment;

final class TicketReplyHandlerTest extends TestCase {

	private FakeDesk $desk;
	private FakeReplyEnvironment $env;
	private TicketReplyHandler $handler;

	protected function setUp(): void {
		$this->desk              = new FakeDesk();
		$this->env               = new FakeReplyEnvironment();
		$this->handler           = new TicketReplyHandler( $this->desk, $this->env );
		$this->desk->tickets[12] = $this->ticket();
	}

	private function ticket( string $status = 'open', bool $archived = false, string $email = 'jane@example.com' ): SupportTicket {
		return new SupportTicket( 12, 1042, 'Where is my order', $email, 'Jane', $status, $archived, '2026-09-01 10:00:00' );
	}

	/**
	 * Builds a Telegram `message` from the operator.
	 *
	 * @param array<string, mixed> $overrides Keys to override.
	 *
	 * @return array<string, mixed>
	 */
	private function message( array $overrides = array() ): array {
		return array_merge(
			array(
				'from' => array( 'id' => 555 ),
				'text' => 'Your order ships tomorrow.',
			),
			$overrides
		);
	}

	public function test_happy_path_sends_via_the_desk_and_confirms_only_afterwards(): void {
		$this->assertTrue( $this->handler->handle( 1, 2, '12', $this->message() ) );

		$this->assertSame( array( array( 12, 'Your order ships tomorrow.', 7 ) ), $this->desk->sent );
		$this->assertSame( array( 'Reply sent to jane@example.com on ticket #1042' ), $this->env->responses );
	}

	public function test_unauthorized_sender_is_rejected_audited_and_nothing_is_sent(): void {
		$this->assertTrue( $this->handler->handle( 1, 2, '12', $this->message( array( 'from' => array( 'id' => 999 ) ) ) ) );

		$this->assertSame( array(), $this->desk->sent );
		$this->assertSame( array( TicketReplyHandler::MSG_UNAUTHORIZED ), $this->env->responses );
		$this->assertSame( array( 'unauthorized' ), $this->env->rejections );
	}

	public function test_a_message_without_a_numeric_sender_is_claimed_silently(): void {
		$this->assertTrue( $this->handler->handle( 1, 2, '12', array( 'text' => 'hi' ) ) );

		$this->assertSame( array(), $this->desk->sent );
		$this->assertSame( array(), $this->env->responses );
	}

	public function test_rate_limited_operator_is_told_and_nothing_is_sent(): void {
		$this->env->allow = false;

		$this->handler->handle( 1, 2, '12', $this->message() );

		$this->assertSame( array(), $this->desk->sent );
		$this->assertSame( array( TicketReplyHandler::MSG_RATE_LIMITED ), $this->env->responses );
	}

	public function test_non_text_reply_is_rejected_with_a_clear_message(): void {
		$this->handler->handle(
			1,
			2,
			'12',
			array(
				'from'  => array( 'id' => 555 ),
				'photo' => array( array( 'file_id' => 'x' ) ),
			)
		);

		$this->assertSame( array(), $this->desk->sent );
		$this->assertSame( array( TicketReplyHandler::MSG_NOT_TEXT ), $this->env->responses );
	}

	public function test_blank_text_is_treated_as_non_text(): void {
		$this->handler->handle( 1, 2, '12', $this->message( array( 'text' => "  \n " ) ) );

		$this->assertSame( array(), $this->desk->sent );
		$this->assertSame( array( TicketReplyHandler::MSG_NOT_TEXT ), $this->env->responses );
	}

	public function test_missing_or_deleted_ticket_is_rejected(): void {
		$this->handler->handle( 1, 2, '99', $this->message() );

		$this->assertSame( array(), $this->desk->sent );
		$this->assertSame( array( TicketReplyHandler::MSG_NO_TICKET ), $this->env->responses );
	}

	public function test_ticket_without_a_customer_email_is_rejected(): void {
		$this->desk->tickets[12] = $this->ticket( 'open', false, '' );

		$this->handler->handle( 1, 2, '12', $this->message() );

		$this->assertSame( array(), $this->desk->sent );
		$this->assertSame( array( TicketReplyHandler::MSG_NO_EMAIL ), $this->env->responses );
	}

	public function test_archived_ticket_is_rejected_with_the_specific_message_and_no_email(): void {
		$this->desk->tickets[12] = $this->ticket( 'open', true );

		$this->handler->handle( 1, 2, '12', $this->message() );

		$this->assertSame( array(), $this->desk->sent );
		$this->assertSame( array( 'This ticket is archived. Unarchive it in WP admin before replying.' ), $this->env->responses );
	}

	public function test_closed_ticket_can_be_replied_to(): void {
		$this->desk->tickets[12] = $this->ticket( 'closed' );

		$this->handler->handle( 1, 2, '12', $this->message() );

		$this->assertCount( 1, $this->desk->sent );
		$this->assertSame( array( 'Reply sent to jane@example.com on ticket #1042' ), $this->env->responses );
	}

	public function test_desk_failure_yields_an_error_notice_and_never_a_success_confirmation(): void {
		$this->desk->send_error = 'Could not send email.';

		$this->handler->handle( 1, 2, '12', $this->message() );

		$this->assertSame( array(), $this->desk->sent );
		$this->assertSame( array( 'The reply could not be sent: Could not send email.' ), $this->env->responses );
		$this->assertStringNotContainsString( 'Reply sent', $this->env->responses[0] );
	}

	/**
	 * @dataProvider invalid_references
	 */
	public function test_invalid_ticket_references_are_rejected( string $suffix ): void {
		$this->handler->handle( 1, 2, $suffix, $this->message() );

		$this->assertSame( array(), $this->desk->sent );
		$this->assertSame( array( TicketReplyHandler::MSG_BAD_REFERENCE ), $this->env->responses );
	}

	/** @return array<string, array<int, string>> */
	public function invalid_references(): array {
		return array(
			'empty'    => array( '' ),
			'text'     => array( 'abc' ),
			'zero'     => array( '0' ),
			'negative' => array( '-3' ),
			'mixed'    => array( '12x' ),
		);
	}
}
