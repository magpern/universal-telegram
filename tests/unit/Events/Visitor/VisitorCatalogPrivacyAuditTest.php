<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Unit\Events\Visitor;

use PHPUnit\Framework\TestCase;
use UniversalTelegram\Events\Registry;
use UniversalTelegram\Events\Visitor\VisitorEventCatalog;
use UniversalTelegram\Integrations\WooCommerce\Visitor\VisitorCommerceEventCatalog;

/**
 * Cross-cutting privacy audit for the full nine-type visitor catalog
 * (M04 plan §7, WP7): no field, in any classification map, ever carries a
 * per-visitor correlation token, a search term/hash, or an error-location
 * derivative — the endpoint's privacy guarantee is structural, not
 * consent-dependent (docs/adr/0019).
 */
final class VisitorCatalogPrivacyAuditTest extends TestCase {

	private const DENYLISTED_SUBSTRINGS = array(
		'visit_ref',
		'search_term',
		'location',
		'ip_address',
		'user_agent',
		'cookie',
		'email',
		'referrer',
		'query',
		'fragment',
		'stack',
		'message',
	);

	private function full_registry(): Registry {
		$registry = new Registry();
		( new VisitorEventCatalog() )->register_event_types( $registry );
		( new VisitorCommerceEventCatalog() )->register_event_types( $registry );

		return $registry;
	}

	public function test_the_full_catalog_has_exactly_nine_visitor_types(): void {
		$registry = $this->full_registry();
		$count    = 0;

		foreach ( $registry->all() as $entry ) {
			if ( 0 === strpos( $entry['event_type'], 'visitor.' ) ) {
				++$count;
			}
		}

		$this->assertSame( 9, $count );
	}

	public function test_no_field_in_any_visitor_type_contains_a_denylisted_substring(): void {
		$registry = $this->full_registry();

		foreach ( $registry->all() as $entry ) {
			if ( 0 !== strpos( $entry['event_type'], 'visitor.' ) ) {
				continue;
			}

			$map = $registry->classification_map_for( $entry['event_type'] );

			foreach ( array_keys( $map ) as $field ) {
				foreach ( self::DENYLISTED_SUBSTRINGS as $denied ) {
					$this->assertStringNotContainsString(
						$denied,
						strtolower( $field ),
						"Field {$field} on {$entry['event_type']} must not reference '{$denied}'."
					);
				}
			}
		}
	}
}
