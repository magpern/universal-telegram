<?php
/**
 * Support-desk event emission.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Integrations\FluentContactInbox\Events;

use UniversalTelegram\Events\Registry;
use UniversalTelegram\Privacy\Classification;

/**
 * Emits the three support-desk event types from the desk's own lifecycle
 * actions (docs/adr/0046). Customer name/email/message are INTERNAL and never
 * reach event history; only ticket number and source are PUBLIC. The three
 * hooks are mutually exclusive on the desk side, so each occurrence maps to
 * exactly one event type.
 */
final class ContactInboxEventEmitter {

	public const CONTACT_REQUEST_SUBMITTED = 'fluent_contact_inbox.contact_request_submitted';
	public const TICKET_CREATED            = 'fluent_contact_inbox.ticket_created';
	public const TICKET_REPLY_RECEIVED     = 'fluent_contact_inbox.ticket_reply_received';

	public const HOOK_CONTACT_REQUEST = 'biopentra_contact_inbox/contact_request_submitted';
	public const HOOK_TICKET_CREATED  = 'biopentra_contact_inbox/ticket_created';
	public const HOOK_MESSAGE_ADDED   = 'biopentra_contact_inbox/message_added';

	public const REPLY_CORRELATION_FIELD = 'subject.ticket_id';

	private const MAX_SUBJECT_CHARS = 200;
	private const MAX_MESSAGE_CHARS = 1500;

	/**
	 * Every event type this emitter registers.
	 *
	 * @return array<int, string>
	 */
	public static function event_types(): array {
		return array( self::CONTACT_REQUEST_SUBMITTED, self::TICKET_CREATED, self::TICKET_REPLY_RECEIVED );
	}

	/**
	 * Registers this emitter's event types.
	 *
	 * @param Registry $registry The current request's event registry.
	 */
	public function register_event_types( Registry $registry ): void {
		$fields = array(
			'actor.user_id'          => Classification::INTERNAL,
			'subject.ticket_id'      => Classification::INTERNAL,
			'payload.ticket_number'  => Classification::PUBLIC,
			'payload.subject'        => Classification::INTERNAL,
			'payload.message_text'   => Classification::INTERNAL,
			'payload.customer_name'  => Classification::INTERNAL,
			'payload.customer_email' => Classification::INTERNAL,
			'payload.source'         => Classification::PUBLIC,
		);

		foreach ( self::event_types() as $event_type ) {
			$registry->register(
				$event_type,
				1,
				$fields,
				array_keys( $fields ),
				array( 'payload.ticket_number', 'payload.source' ),
				self::REPLY_CORRELATION_FIELD
			);
		}
	}

	/**
	 * Binds the support desk's lifecycle actions. Called only when
	 * FluentContactInboxSupport::is_active() is true.
	 */
	public function register_hooks(): void {
		add_action( self::HOOK_CONTACT_REQUEST, array( $this, 'on_contact_request_submitted' ), 10, 2 );
		add_action( self::HOOK_TICKET_CREATED, array( $this, 'on_ticket_created' ), 10, 2 );
		add_action( self::HOOK_MESSAGE_ADDED, array( $this, 'on_message_added' ), 10, 2 );
	}

	/**
	 * A live contact-form submission created a ticket.
	 *
	 * @param int                  $ticket_id Ticket id.
	 * @param array<string, mixed> $meta      Desk metadata.
	 */
	public function on_contact_request_submitted( int $ticket_id, array $meta ): void {
		$this->emit( self::CONTACT_REQUEST_SUBMITTED, $ticket_id, $meta, 'ticket:' . $ticket_id . ':contact_request' );
	}

	/**
	 * An imported email created a ticket.
	 *
	 * @param int                  $ticket_id Ticket id.
	 * @param array<string, mixed> $meta      Desk metadata.
	 */
	public function on_ticket_created( int $ticket_id, array $meta ): void {
		$this->emit( self::TICKET_CREATED, $ticket_id, $meta, 'ticket:' . $ticket_id . ':created' );
	}

	/**
	 * An imported email joined an existing ticket.
	 *
	 * @param int                  $ticket_id Ticket id.
	 * @param array<string, mixed> $meta      Desk metadata.
	 */
	public function on_message_added( int $ticket_id, array $meta ): void {
		if ( isset( $meta['direction'] ) && 'inbound' !== $meta['direction'] ) {
			return;
		}

		$message_row_id = isset( $meta['message_row_id'] ) ? (int) $meta['message_row_id'] : 0;
		$key_suffix     = $message_row_id > 0
			? (string) $message_row_id
			: substr( sha1( (string) ( $meta['customer_email'] ?? '' ) . "\n" . (string) ( $meta['message_text'] ?? '' ) ), 0, 16 );

		$this->emit( self::TICKET_REPLY_RECEIVED, $ticket_id, $meta, 'ticket:' . $ticket_id . ':message:' . $key_suffix );
	}

	/**
	 * Builds the envelope data for one occurrence.
	 *
	 * @param int                  $ticket_id Ticket id.
	 * @param array<string, mixed> $meta      Desk metadata.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function build_data( int $ticket_id, array $meta ): array {
		$source = isset( $meta['source'] ) && 'fluent' === $meta['source'] ? 'fluent' : 'email';

		return array(
			'actor'   => array(
				'user_id' => function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0,
			),
			'subject' => array(
				'ticket_id' => $ticket_id,
			),
			'payload' => array(
				'ticket_number'  => isset( $meta['ticket_number'] ) ? (int) $meta['ticket_number'] : $ticket_id,
				'subject'        => self::truncate( (string) ( $meta['subject'] ?? '' ), self::MAX_SUBJECT_CHARS ),
				'message_text'   => self::truncate( (string) ( $meta['message_text'] ?? '' ), self::MAX_MESSAGE_CHARS ),
				'customer_name'  => self::truncate( (string) ( $meta['customer_name'] ?? '' ), self::MAX_SUBJECT_CHARS ),
				'customer_email' => self::truncate( (string) ( $meta['customer_email'] ?? '' ), self::MAX_SUBJECT_CHARS ),
				'source'         => $source,
			),
		);
	}

	/**
	 * Emits one occurrence.
	 *
	 * @param string               $event_type      The event type.
	 * @param int                  $ticket_id       Ticket id.
	 * @param array<string, mixed> $meta            Desk metadata.
	 * @param string               $idempotency_key Occurrence key.
	 */
	private function emit( string $event_type, int $ticket_id, array $meta, string $idempotency_key ): void {
		if ( $ticket_id <= 0 ) {
			return;
		}

		universal_telegram_emit_event( $event_type, $this->build_data( $ticket_id, $meta ), $idempotency_key );
	}

	/**
	 * Length-caps a value (keeps one notification well below Telegram's message limit).
	 *
	 * @param string $value Raw value.
	 * @param int    $max   Maximum characters.
	 *
	 * @return string
	 */
	private static function truncate( string $value, int $max ): string {
		$value = trim( $value );

		if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
			return mb_strlen( $value ) > $max ? mb_substr( $value, 0, $max - 1 ) . '…' : $value;
		}

		return strlen( $value ) > $max ? substr( $value, 0, $max - 1 ) . '…' : $value;
	}
}
