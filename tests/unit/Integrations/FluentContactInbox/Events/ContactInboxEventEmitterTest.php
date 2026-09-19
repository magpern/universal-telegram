<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integrations\FluentContactInbox\Events;

use PHPUnit\Framework\TestCase;
use UniversalTelegram\Administration\Automations\EventCatalogLabels;
use UniversalTelegram\Administration\Automations\FieldTypeCatalog;
use UniversalTelegram\Events\Registry;
use UniversalTelegram\Integrations\FluentContactInbox\Events\ContactInboxEventEmitter;
use UniversalTelegram\Privacy\Classification;

final class ContactInboxEventEmitterTest extends TestCase {

	private function registry(): Registry {
		$registry = new Registry();
		( new ContactInboxEventEmitter() )->register_event_types( $registry );

		return $registry;
	}

	public function test_registers_exactly_the_three_support_event_types(): void {
		$registry = $this->registry();

		$this->assertSame(
			array(
				'fluent_contact_inbox.contact_request_submitted',
				'fluent_contact_inbox.ticket_created',
				'fluent_contact_inbox.ticket_reply_received',
			),
			array_map( static fn ( array $entry ): string => $entry['event_type'], $registry->all() )
		);
	}

	public function test_customer_data_is_internal_and_only_public_fields_reach_history(): void {
		$registry = $this->registry();

		foreach ( ContactInboxEventEmitter::event_types() as $event_type ) {
			$map = $registry->classification_map_for( $event_type );

			foreach ( array( 'payload.customer_email', 'payload.customer_name', 'payload.message_text', 'payload.subject', 'subject.ticket_id', 'actor.user_id' ) as $internal ) {
				$this->assertSame( Classification::INTERNAL, $map[ $internal ], "{$internal} on {$event_type}" );
			}

			$this->assertSame( Classification::PUBLIC, $map['payload.ticket_number'] );
			$this->assertSame( Classification::PUBLIC, $map['payload.source'] );
			$this->assertSame( array( 'payload.ticket_number', 'payload.source' ), $registry->history_projection_fields_for( $event_type ) );
		}
	}

	public function test_each_event_type_declares_the_ticket_id_as_reply_correlation_field(): void {
		$registry = $this->registry();

		foreach ( ContactInboxEventEmitter::event_types() as $event_type ) {
			$this->assertSame( 'subject.ticket_id', $registry->reply_correlation_field_for( $event_type ) );
		}

		$this->assertNull( $registry->reply_correlation_field_for( 'unknown.type' ) );
	}

	public function test_registry_rejects_an_unclassified_correlation_field(): void {
		$this->expectException( \UniversalTelegram\Events\UnclassifiedFieldException::class );

		( new Registry() )->register( 'x.y', 1, array( 'a.b' => Classification::PUBLIC ), array( 'a.b' ), array(), 'not.there' );
	}

	public function test_existing_registrations_keep_no_correlation_field(): void {
		$registry = new Registry();
		$registry->register( 'x.y', 1, array( 'a.b' => Classification::PUBLIC ), array( 'a.b' ), array( 'a.b' ) );

		$this->assertNull( $registry->reply_correlation_field_for( 'x.y' ) );
	}

	public function test_every_allowed_field_is_catalogued_and_labelled_for_the_rule_builder(): void {
		$registry = $this->registry();

		foreach ( ContactInboxEventEmitter::event_types() as $event_type ) {
			$this->assertTrue( EventCatalogLabels::has_event_type_label( $event_type ), $event_type );

			foreach ( $registry->allowed_variable_fields_for( $event_type ) as $field ) {
				$this->assertTrue( FieldTypeCatalog::has( $field ), "{$field} missing from FieldTypeCatalog" );
				$this->assertTrue( EventCatalogLabels::has_field_label( $field ), "{$field} missing a label" );
			}
		}
	}

	public function test_envelope_data_carries_the_documented_fields_and_caps_long_text(): void {
		$data = ( new ContactInboxEventEmitter() )->build_data(
			42,
			array(
				'source'         => 'fluent',
				'subject'        => 'Hello',
				'customer_email' => 'jane@example.com',
				'customer_name'  => 'Jane',
				'message_text'   => str_repeat( 'x', 5000 ),
				'ticket_number'  => 1042,
			)
		);

		$this->assertSame( 42, $data['subject']['ticket_id'] );
		$this->assertSame( 1042, $data['payload']['ticket_number'] );
		$this->assertSame( 'fluent', $data['payload']['source'] );
		$this->assertSame( 'jane@example.com', $data['payload']['customer_email'] );
		$this->assertLessThanOrEqual( 1500, mb_strlen( $data['payload']['message_text'] ) );
		$this->assertStringEndsWith( '…', $data['payload']['message_text'] );
	}

	public function test_unknown_source_falls_back_to_email_and_ticket_number_to_the_id(): void {
		$data = ( new ContactInboxEventEmitter() )->build_data( 7, array( 'source' => 'weird' ) );

		$this->assertSame( 'email', $data['payload']['source'] );
		$this->assertSame( 7, $data['payload']['ticket_number'] );
	}
}
