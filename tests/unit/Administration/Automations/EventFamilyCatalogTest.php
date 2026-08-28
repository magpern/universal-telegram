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

				if ( $family['requires_woocommerce'] ) {
					$this->assertTrue( $is_woocommerce_type, "Family '{$family_id}' is flagged requires_woocommerce but contains non-WooCommerce event type '{$event_type}'." );
				} else {
					$this->assertFalse( $is_woocommerce_type, "Family '{$family_id}' is not flagged requires_woocommerce but contains WooCommerce event type '{$event_type}'." );
				}
			}
		}
	}
}
