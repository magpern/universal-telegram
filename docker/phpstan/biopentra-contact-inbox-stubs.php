<?php
/**
 * Declaration-only PHPStan stubs for the optional fluent-imap-support-desk plugin
 * (docs/adr/0046). Never loaded at runtime and never shipped; they only let static
 * analysis type-check src/Integrations/FluentContactInbox/FluentContactInboxGateway.php.
 */

// phpcs:ignoreFile

class Biopentra_Contact_Inbox_Ticket_Repository {

	/**
	 * @param int $id Ticket id.
	 * @return object|null
	 */
	public static function get( $id ) {
		return null;
	}

	/**
	 * @param array<string, mixed> $args Filters.
	 * @return int
	 */
	public static function count_tickets( array $args ) {
		return 0;
	}

	/**
	 * @param array<string, mixed> $args Filters.
	 * @return array{items: array<int, object>, total: int}
	 */
	public static function list_tickets( array $args ) {
		return array(
			'items' => array(),
			'total' => 0,
		);
	}
}

class Biopentra_Contact_Inbox_Ticket_Reply {

	/**
	 * @param int    $ticket_id     Ticket id.
	 * @param string $to            Recipient.
	 * @param string $subject       Subject.
	 * @param string $body          HTML body.
	 * @param int    $admin_user_id User id.
	 * @return bool|\WP_Error
	 */
	public static function send( $ticket_id, $to, $subject, $body, $admin_user_id = 0 ) {
		return true;
	}
}
