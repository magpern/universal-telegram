<?php
/**
 * Read model of one support-desk ticket.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Integrations\FluentContactInbox;

/**
 * The few ticket facts this plugin needs, so nothing outside the gateway
 * touches the support desk's own row objects.
 */
final class SupportTicket {

	/**
	 * Constructor.
	 *
	 * @param int    $id              Ticket primary key.
	 * @param int    $ticket_number   Customer-facing number.
	 * @param string $subject         Ticket subject.
	 * @param string $customer_email  Customer email, empty when unknown.
	 * @param string $customer_name   Customer name, may be empty.
	 * @param string $status          open|pending|closed.
	 * @param bool   $archived        Whether the ticket is archived.
	 * @param string $last_message_at Site-local MySQL datetime of the last message.
	 */
	public function __construct(
		public readonly int $id,
		public readonly int $ticket_number,
		public readonly string $subject,
		public readonly string $customer_email,
		public readonly string $customer_name,
		public readonly string $status,
		public readonly bool $archived,
		public readonly string $last_message_at
	) {}
}
