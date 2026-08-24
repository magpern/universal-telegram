<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Administration\Automations;

use UniversalTelegram\Administration\Automations\EventCatalogLabels;
use UniversalTelegram\Administration\Automations\EventCatalogPage;
use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;
use UniversalTelegram\Core\Plugin;
use WP_UnitTestCase;

final class EventCatalogPageTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();

		( new CapabilityRegistrar() )->grant_to_administrator();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function test_rendered_catalog_includes_descriptions_labels_and_column_help(): void {
		$page = new EventCatalogPage( Plugin::instance()->event_registry() );

		ob_start();
		$page->render_tab_content();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Plain-language name for this event.', $html );
		$this->assertStringContainsString( 'Non-sensitive fields stored in the event history log.', $html );
		$this->assertStringContainsString( 'Visitor viewed a page', $html );
		$this->assertStringContainsString( '<code>visitor.page_viewed</code>', $html );
		$this->assertStringContainsString( 'Page path', $html );
		$this->assertStringContainsString( '<code>subject.path</code>', $html );
	}

	public function test_every_registered_event_type_has_an_explicit_admin_label(): void {
		foreach ( Plugin::instance()->event_registry()->all() as $entry ) {
			$this->assertTrue(
				EventCatalogLabels::has_event_type_label( $entry['event_type'] ),
				sprintf( 'Missing admin label for event type "%s".', $entry['event_type'] )
			);
		}
	}

	public function test_every_registered_field_path_has_an_explicit_admin_label(): void {
		$field_paths = array();

		foreach ( Plugin::instance()->event_registry()->all() as $entry ) {
			foreach ( $entry['allowed_variable_fields'] as $field ) {
				$field_paths[ $field ] = true;
			}
			foreach ( $entry['history_projection_fields'] as $field ) {
				$field_paths[ $field ] = true;
			}
		}

		foreach ( array_keys( $field_paths ) as $field_path ) {
			$this->assertTrue(
				EventCatalogLabels::has_field_label( $field_path ),
				sprintf( 'Missing admin label for field "%s".', $field_path )
			);
		}
	}
}
