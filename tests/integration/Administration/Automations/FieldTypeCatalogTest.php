<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Administration\Automations;

use UniversalTelegram\Administration\Automations\EventCatalogLabels;
use UniversalTelegram\Administration\Automations\FieldTypeCatalog;
use UniversalTelegram\Automations\ConditionOperator;
use UniversalTelegram\Core\Plugin;
use WP_UnitTestCase;

/**
 * The fail-closed coverage gate for M08.1's field-type metadata (plan
 * "Field type metadata"): every field the friendly condition builder and
 * message field-insert menu can offer must be both an engine-allowed
 * variable field and a fully specified FieldTypeCatalog entry — never a
 * generic-text default for something the engine allows but this catalog
 * hasn't deliberately covered.
 */
final class FieldTypeCatalogTest extends WP_UnitTestCase {

	private const VALID_TYPES = array(
		FieldTypeCatalog::TYPE_TEXT,
		FieldTypeCatalog::TYPE_NUMBER,
		FieldTypeCatalog::TYPE_MONEY,
		FieldTypeCatalog::TYPE_BOOLEAN,
		FieldTypeCatalog::TYPE_CHOICE,
	);

	public function test_every_catalogued_field_is_allowed_by_at_least_one_registered_event_type(): void {
		$allowed_fields = array();

		foreach ( Plugin::instance()->event_registry()->all() as $entry ) {
			foreach ( $entry['allowed_variable_fields'] as $field ) {
				$allowed_fields[ $field ] = true;
			}
		}

		foreach ( FieldTypeCatalog::all_field_paths() as $field_path ) {
			$this->assertArrayHasKey(
				$field_path,
				$allowed_fields,
				sprintf( 'FieldTypeCatalog field "%s" is not an allowed variable field for any registered event type.', $field_path )
			);
		}
	}

	public function test_every_catalogued_field_is_fully_specified(): void {
		foreach ( FieldTypeCatalog::all_field_paths() as $field_path ) {
			$type = FieldTypeCatalog::type( $field_path );
			$this->assertContains( $type, self::VALID_TYPES, sprintf( 'Field "%s" has an invalid or missing type.', $field_path ) );

			$label = FieldTypeCatalog::label( $field_path );
			$this->assertNotSame( '', (string) $label, sprintf( 'Field "%s" has an empty label.', $field_path ) );
			$this->assertTrue(
				EventCatalogLabels::has_field_label( $field_path ),
				sprintf( 'Field "%s" has no corresponding EventCatalogLabels entry to source its label from.', $field_path )
			);

			$operators = FieldTypeCatalog::operators( $field_path );
			$this->assertNotEmpty( $operators, sprintf( 'Field "%s" has no permitted operators.', $field_path ) );
			foreach ( $operators as $operator ) {
				$this->assertNotNull(
					ConditionOperator::tryFrom( $operator ),
					sprintf( 'Field "%s" lists unknown operator "%s".', $field_path, $operator )
				);
			}

			$preview = FieldTypeCatalog::preview_value( $field_path );
			$this->assertNotSame( '', (string) $preview, sprintf( 'Field "%s" has an empty preview value.', $field_path ) );

			if ( FieldTypeCatalog::TYPE_CHOICE === $type ) {
				$this->assertNotEmpty( FieldTypeCatalog::choice_options( $field_path ), sprintf( 'Choice field "%s" has no choice options.', $field_path ) );
				$this->assertArrayHasKey(
					(string) $preview,
					FieldTypeCatalog::choice_options( $field_path ),
					sprintf( 'Choice field "%s" preview value is not one of its own choice options.', $field_path )
				);
			}
		}
	}

	public function test_an_uncatalogued_field_reports_absent_rather_than_a_generic_default(): void {
		$this->assertFalse( FieldTypeCatalog::has( 'payload.this_field_does_not_exist' ) );
		$this->assertNull( FieldTypeCatalog::type( 'payload.this_field_does_not_exist' ) );
		$this->assertNull( FieldTypeCatalog::label( 'payload.this_field_does_not_exist' ) );
		$this->assertSame( array(), FieldTypeCatalog::operators( 'payload.this_field_does_not_exist' ) );
		$this->assertNull( FieldTypeCatalog::preview_value( 'payload.this_field_does_not_exist' ) );
	}
}
