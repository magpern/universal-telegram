<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Unit\Events\Visitor;

use PHPUnit\Framework\TestCase;
use UniversalTelegram\Events\Registry;
use UniversalTelegram\Events\Visitor\VisitorEventCatalog;
use UniversalTelegram\Privacy\Classification;

final class VisitorEventCatalogTest extends TestCase {

	private function registered_registry(): Registry {
		$registry = new Registry();
		( new VisitorEventCatalog() )->register_event_types( $registry );

		return $registry;
	}

	public function test_registers_exactly_the_six_core_visitor_types(): void {
		$registry = $this->registered_registry();

		$this->assertTrue( $registry->is_registered( 'visitor.session_started' ) );
		$this->assertTrue( $registry->is_registered( 'visitor.page_viewed' ) );
		$this->assertTrue( $registry->is_registered( 'visitor.navigation' ) );
		$this->assertTrue( $registry->is_registered( 'visitor.search_performed' ) );
		$this->assertTrue( $registry->is_registered( 'visitor.click' ) );
		$this->assertTrue( $registry->is_registered( 'visitor.javascript_error' ) );
	}

	public function test_every_registered_field_is_classified_public(): void {
		$registry = $this->registered_registry();

		foreach ( $registry->all() as $entry ) {
			if ( 0 !== strpos( $entry['event_type'], 'visitor.' ) ) {
				continue;
			}

			$map = $registry->classification_map_for( $entry['event_type'] );

			foreach ( $map as $field => $classification ) {
				$this->assertSame( Classification::PUBLIC, $classification, "Field {$field} on {$entry['event_type']} must be PUBLIC." );
			}
		}
	}

	public function test_no_visitor_type_has_a_visit_ref_field(): void {
		$registry = $this->registered_registry();

		foreach ( $registry->all() as $entry ) {
			if ( 0 !== strpos( $entry['event_type'], 'visitor.' ) ) {
				continue;
			}

			$map = $registry->classification_map_for( $entry['event_type'] );

			foreach ( array_keys( $map ) as $field ) {
				$this->assertStringNotContainsString( 'visit_ref', $field );
			}
		}
	}

	public function test_history_projection_fields_are_a_subset_of_allowed_variable_fields(): void {
		$registry = $this->registered_registry();

		foreach ( $registry->all() as $entry ) {
			if ( 0 !== strpos( $entry['event_type'], 'visitor.' ) ) {
				continue;
			}

			foreach ( $entry['history_projection_fields'] as $field ) {
				$this->assertContains( $field, $entry['allowed_variable_fields'] );
			}
		}
	}
}
