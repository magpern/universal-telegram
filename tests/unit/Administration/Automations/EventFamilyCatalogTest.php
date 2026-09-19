<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Administration\Automations;

use PHPUnit\Framework\TestCase;
use UniversalTelegram\Administration\Automations\EventFamilyCatalog;

/**
 * M08.2 plan §7 WP2: EventFamilyCatalog is a pure relocation of
 * RuleBuilderPage's own private EVENT_FAMILIES const, so this suite
 * establishes the coverage/uniqueness invariants that let both the rule
 * builder and the notification tester rely on the same data.
 */
final class EventFamilyCatalogTest extends TestCase {

	public function test_every_family_has_at_least_one_event_type(): void {
		foreach ( EventFamilyCatalog::families() as $family_id => $family ) { // phpcs:ignore PHPCompatibility.Extensions.RemovedExtensions.famRemoved -- false positive: the sniff misidentifies the `families(` call as the removed ext/fam extension.
			$this->assertNotSame( array(), $family['event_types'], "Family '{$family_id}' has no event types." );
		}
	}

	public function test_every_event_type_belongs_to_exactly_one_family(): void {
		$seen = array();

		foreach ( EventFamilyCatalog::families() as $family ) { // phpcs:ignore PHPCompatibility.Extensions.RemovedExtensions.famRemoved -- false positive: the sniff misidentifies the `families(` call as the removed ext/fam extension.
			foreach ( $family['event_types'] as $event_type ) {
				$this->assertArrayNotHasKey( $event_type, $seen, "Event type '{$event_type}' appears in more than one family." );
				$seen[ $event_type ] = true;
			}
		}
	}

	public function test_every_woocommerce_flagged_family_contains_only_woocommerce_event_types(): void {
		foreach ( EventFamilyCatalog::families() as $family_id => $family ) { // phpcs:ignore PHPCompatibility.Extensions.RemovedExtensions.famRemoved -- false positive: the sniff misidentifies the `families(` call as the removed ext/fam extension.
			foreach ( $family['event_types'] as $event_type ) {
				$is_woocommerce_type = str_starts_with( $event_type, 'woocommerce.' );

				if ( EventFamilyCatalog::INTEGRATION_WOOCOMMERCE === $family['requires_integration'] ) {
					$this->assertTrue( $is_woocommerce_type, "Family '{$family_id}' requires WooCommerce but contains non-WooCommerce event type '{$event_type}'." );
				} else {
					$this->assertFalse( $is_woocommerce_type, "Family '{$family_id}' does not require WooCommerce but contains WooCommerce event type '{$event_type}'." );
				}
			}
		}
	}

	public function test_support_family_requires_the_support_desk_integration_and_holds_exactly_the_three_events(): void {
		$family = EventFamilyCatalog::families()['support_tickets']; // phpcs:ignore PHPCompatibility.Extensions.RemovedExtensions.famRemoved -- false positive.

		$this->assertSame( EventFamilyCatalog::INTEGRATION_FLUENT_CONTACT_INBOX, $family['requires_integration'] );
		$this->assertSame(
			array(
				'fluent_contact_inbox.contact_request_submitted',
				'fluent_contact_inbox.ticket_created',
				'fluent_contact_inbox.ticket_reply_received',
			),
			$family['event_types']
		);
	}

	public function test_family_availability_follows_its_required_integration(): void {
		$families = EventFamilyCatalog::families(); // phpcs:ignore PHPCompatibility.Extensions.RemovedExtensions.famRemoved -- false positive.

		$none = array(
			EventFamilyCatalog::INTEGRATION_WOOCOMMERCE => false,
			EventFamilyCatalog::INTEGRATION_FLUENT_CONTACT_INBOX => false,
		);

		$this->assertTrue( EventFamilyCatalog::is_family_available( $families['website_and_users'], $none ) );
		$this->assertFalse( EventFamilyCatalog::is_family_available( $families['store_orders'], $none ) );
		$this->assertFalse( EventFamilyCatalog::is_family_available( $families['support_tickets'], $none ) );

		$desk_only = array_merge( $none, array( EventFamilyCatalog::INTEGRATION_FLUENT_CONTACT_INBOX => true ) );

		$this->assertTrue( EventFamilyCatalog::is_family_available( $families['support_tickets'], $desk_only ) );
		$this->assertFalse( EventFamilyCatalog::is_family_available( $families['store_orders'], $desk_only ) );
		$this->assertFalse( EventFamilyCatalog::is_family_available( $families['support_tickets'], array() ) );
	}
}
