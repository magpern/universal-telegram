<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Administration\Automations;

use UniversalTelegram\Administration\Automations\FieldTypeCatalog;
use UniversalTelegram\Administration\Automations\PresetCatalog;
use UniversalTelegram\Automations\ConditionOperator;
use UniversalTelegram\Core\Plugin;
use WP_UnitTestCase;

final class PresetCatalogTest extends WP_UnitTestCase {

	public function test_every_preset_references_a_registered_event_type(): void {
		if ( ! getenv( 'UT_TEST_WC_ACTIVE' ) ) {
			$this->markTestSkipped( 'Several presets reference WooCommerce event types, registered only when WooCommerce is active.' );
		}

		$registry = Plugin::instance()->event_registry();

		foreach ( PresetCatalog::all() as $preset ) {
			$this->assertTrue(
				$registry->is_registered( $preset['event_type'] ),
				sprintf( 'Preset "%s" references unregistered event type "%s".', $preset['key'], $preset['event_type'] )
			);
		}
	}

	public function test_every_preset_conditions_use_only_allowed_and_catalogued_fields(): void {
		$registry = Plugin::instance()->event_registry();

		foreach ( PresetCatalog::all() as $preset ) {
			$allowed = $registry->allowed_variable_fields_for( $preset['event_type'] );

			foreach ( $preset['conditions'] as $clause ) {
				$this->assertContains(
					$clause['field'],
					$allowed,
					sprintf( 'Preset "%s" condition field "%s" is not allowed for event type "%s".', $preset['key'], $clause['field'], $preset['event_type'] )
				);
				$this->assertTrue(
					FieldTypeCatalog::has( $clause['field'] ),
					sprintf( 'Preset "%s" condition field "%s" is not catalogued in FieldTypeCatalog.', $preset['key'], $clause['field'] )
				);
				$this->assertNotNull(
					ConditionOperator::tryFrom( (string) $clause['operator'] ),
					sprintf( 'Preset "%s" uses unknown operator "%s".', $preset['key'], (string) $clause['operator'] )
				);
			}
		}
	}

	public function test_every_preset_key_is_unique(): void {
		$keys = array_column( PresetCatalog::all(), 'key' );

		$this->assertSame( count( $keys ), count( array_unique( $keys ) ) );
	}

	public function test_every_preset_message_has_no_emoji_and_is_non_empty(): void {
		foreach ( PresetCatalog::all() as $preset ) {
			$this->assertNotSame( '', trim( $preset['message'] ) );

			// A representative ASCII-range check: no characters outside the
			// Basic Multilingual Plane's common punctuation/letters beyond
			// the em dash explicitly used in one preset's own text.
			$this->assertMatchesRegularExpression(
				'/^[\x{0000}-\x{024F}\x{2013}\x{2014}\x{2018}\x{2019}\x{201C}\x{201D}]*$/u',
				$preset['message'],
				sprintf( 'Preset "%s" message contains an unexpected non-Latin character (possible emoji).', $preset['key'] )
			);
		}
	}

	public function test_woocommerce_gated_presets_reference_woocommerce_event_types(): void {
		foreach ( PresetCatalog::all() as $preset ) {
			if ( ! $preset['requires_woocommerce'] ) {
				continue;
			}

			$this->assertTrue(
				str_starts_with( $preset['event_type'], 'woocommerce.' ),
				sprintf( 'Preset "%s" is flagged WooCommerce-required but its event type "%s" is not WooCommerce-scoped.', $preset['key'], $preset['event_type'] )
			);
		}
	}

	public function test_starter_set_is_exactly_new_order_order_failed_and_low_stock(): void {
		$keys = array_column( PresetCatalog::starter_set(), 'key' );

		$this->assertSame( array( 'new_order', 'order_failed', 'low_stock' ), $keys );

		foreach ( PresetCatalog::starter_set() as $preset ) {
			$this->assertTrue( $preset['requires_woocommerce'] );
		}
	}
}
