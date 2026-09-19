<?php
/**
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Tests\Integrations\FluentContactInbox\Support;

use UniversalTelegram\Integrations\FluentContactInbox\SupportDeskGateway;
use UniversalTelegram\Integrations\FluentContactInbox\SupportTicket;

/**
 * In-memory support desk for handler and digest tests.
 */
final class FakeDesk implements SupportDeskGateway {

	/**
	 * Tickets by id.
	 *
	 * @var array<int, SupportTicket>
	 */
	public array $tickets = array();

	/**
	 * When set, send_reply() fails with this reason.
	 *
	 * @var string|null
	 */
	public ?string $send_error = null;

	/**
	 * Recorded replies: ticket id, text, WP user id.
	 *
	 * @var array<int, array{0: int, 1: string, 2: int}>
	 */
	public array $sent = array();

	/**
	 * Open-ticket count for the digest.
	 *
	 * @var int
	 */
	public int $open = 3;

	/**
	 * Pending-ticket count for the digest.
	 *
	 * @var int
	 */
	public int $pending = 2;

	/**
	 * Oldest open tickets for the digest.
	 *
	 * @var array<int, SupportTicket>
	 */
	public array $oldest = array();

	/**
	 * {@inheritDoc}
	 *
	 * @param int $ticket_id Ticket id.
	 */
	public function find_ticket( int $ticket_id ): ?SupportTicket {
		return $this->tickets[ $ticket_id ] ?? null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param SupportTicket $ticket     The ticket.
	 * @param string        $text       The reply.
	 * @param int           $wp_user_id The operator.
	 */
	public function send_reply( SupportTicket $ticket, string $text, int $wp_user_id ): ?string {
		if ( null !== $this->send_error ) {
			return $this->send_error;
		}

		$this->sent[] = array( $ticket->id, $text, $wp_user_id );

		return null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $status Status.
	 */
	public function count_by_status( string $status ): int {
		return 'open' === $status ? $this->open : $this->pending;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $limit Limit.
	 */
	public function oldest_open( int $limit ): array {
		return $this->oldest;
	}
}
