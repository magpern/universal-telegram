<?php
/**
 * Port to the support desk.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Integrations\FluentContactInbox;

/**
 * Everything this plugin does to the support desk goes through this port:
 * the production adapter is {@see FluentContactInboxGateway}; tests inject a
 * fake.
 */
interface SupportDeskGateway {

	/**
	 * The ticket, or null when it does not exist (e.g. deleted).
	 *
	 * @param int $ticket_id Ticket primary key.
	 *
	 * @return SupportTicket|null
	 */
	public function find_ticket( int $ticket_id ): ?SupportTicket;

	/**
	 * Sends a staff reply through the desk's canonical reply operation.
	 *
	 * @param SupportTicket $ticket     The ticket being answered.
	 * @param string        $text       The manager's plain-text reply.
	 * @param int           $wp_user_id The WordPress user credited with the reply.
	 *
	 * @return string|null Null on success; a human-readable failure reason otherwise.
	 */
	public function send_reply( SupportTicket $ticket, string $text, int $wp_user_id ): ?string;

	/**
	 * Number of non-archived tickets in a status.
	 *
	 * @param string $status open|pending|closed.
	 *
	 * @return int
	 */
	public function count_by_status( string $status ): int;

	/**
	 * The oldest non-archived open tickets, oldest first.
	 *
	 * @param int $limit Maximum number of tickets.
	 *
	 * @return array<int, SupportTicket>
	 */
	public function oldest_open( int $limit ): array;
}
