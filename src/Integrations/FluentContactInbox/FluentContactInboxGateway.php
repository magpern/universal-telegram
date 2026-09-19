<?php
/**
 * Production adapter over the support-desk plugin.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Integrations\FluentContactInbox;

/**
 * Wraps the support desk's own classes (`Biopentra_Contact_Inbox_*`). The
 * desk loads lazily, so every entry point first asks it to load the reply
 * subset (`biopentra_inbox_load_reply_runtime()`); if the desk is missing
 * or too old every method degrades to "unavailable" rather than fataling.
 */
final class FluentContactInboxGateway implements SupportDeskGateway {

	/**
	 * {@inheritDoc}
	 *
	 * @param int $ticket_id Ticket primary key.
	 */
	public function find_ticket( int $ticket_id ): ?SupportTicket {
		if ( ! $this->load() ) {
			return null;
		}

		$row = \Biopentra_Contact_Inbox_Ticket_Repository::get( $ticket_id );

		return is_object( $row ) ? $this->to_ticket( $row ) : null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param SupportTicket $ticket     The ticket being answered.
	 * @param string        $text       The manager's plain-text reply.
	 * @param int           $wp_user_id The WordPress user credited with the reply.
	 */
	public function send_reply( SupportTicket $ticket, string $text, int $wp_user_id ): ?string {
		if ( ! $this->load() || ! class_exists( 'Biopentra_Contact_Inbox_Ticket_Reply' ) ) {
			return 'The support desk is not available.';
		}

		$result = \Biopentra_Contact_Inbox_Ticket_Reply::send(
			$ticket->id,
			$ticket->customer_email,
			ReplyComposer::subject( $ticket->subject ),
			ReplyComposer::html_body( $text ),
			$wp_user_id
		);

		if ( true === $result ) {
			return null;
		}

		return $result instanceof \WP_Error ? $result->get_error_message() : 'The reply could not be sent.';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $status open|pending|closed.
	 */
	public function count_by_status( string $status ): int {
		if ( ! $this->load() ) {
			return 0;
		}

		return (int) \Biopentra_Contact_Inbox_Ticket_Repository::count_tickets( array( 'desk_status' => $status ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $limit Maximum number of tickets.
	 */
	public function oldest_open( int $limit ): array {
		if ( ! $this->load() ) {
			return array();
		}

		$list = \Biopentra_Contact_Inbox_Ticket_Repository::list_tickets(
			array(
				'desk_status' => 'open',
				'per_page'    => max( 1, $limit ),
				'orderby'     => 'last_message_at',
				'order'       => 'asc',
			)
		);

		$tickets = array();

		foreach ( $list['items'] as $row ) {
			$tickets[] = $this->to_ticket( $row );
		}

		return $tickets;
	}

	/**
	 * Loads the desk's reply/read subset; false when unavailable.
	 *
	 * @return bool
	 */
	private function load(): bool {
		if ( ! function_exists( 'biopentra_inbox_load_reply_runtime' ) ) {
			return false;
		}

		biopentra_inbox_load_reply_runtime();

		return class_exists( 'Biopentra_Contact_Inbox_Ticket_Repository' );
	}

	/**
	 * Maps a desk ticket row to the read model.
	 *
	 * @param object $row Ticket row.
	 *
	 * @return SupportTicket
	 */
	private function to_ticket( object $row ): SupportTicket {
		$archived_at = isset( $row->archived_at ) ? (string) $row->archived_at : '';
		$number      = isset( $row->ticket_number ) ? (int) $row->ticket_number : 0;

		return new SupportTicket(
			(int) $row->id,
			$number > 0 ? $number : (int) $row->id,
			isset( $row->subject ) ? (string) $row->subject : '',
			isset( $row->customer_email ) ? (string) $row->customer_email : '',
			isset( $row->customer_name ) ? (string) $row->customer_name : '',
			isset( $row->status ) ? (string) $row->status : 'open',
			'' !== $archived_at && '0000-00-00 00:00:00' !== $archived_at,
			isset( $row->last_message_at ) ? (string) $row->last_message_at : ''
		);
	}
}
