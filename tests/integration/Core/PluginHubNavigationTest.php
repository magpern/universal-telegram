<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Core;

use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;
use UniversalTelegram\Core\Plugin;
use WP_UnitTestCase;

/**
 * M08.2 navigation addendum: the real, plugin-wired hub tab registry must
 * register exactly the seven grouped top-level areas, in the Product
 * Owner's own requested order, with none of the nine moved child screens
 * left registered a second time as their own top-level tab (which would
 * both duplicate the screen and break the legacy-alias fallback).
 */
final class PluginHubNavigationTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();

		( new CapabilityRegistrar() )->grant_to_administrator();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function test_the_hub_registers_exactly_the_seven_grouped_areas_in_order(): void {
		$registry = Plugin::instance()->hub_tab_registry();
		$this->assertNotNull( $registry );

		$ids = array_map( static fn( $tab ) => $tab->id(), $registry->all() );

		$this->assertSame(
			array( 'overview', 'bots', 'notifications-activity', 'conversations', 'ai-hub', 'settings', 'diagnostics' ),
			$ids
		);
	}

	/**
	 * Every screen the addendum moved into a grouped area must no longer
	 * be independently registered as its own top-level tab — otherwise it
	 * would be reachable and rendered twice, defeating the whole point of
	 * grouping.
	 */
	public function test_no_moved_screen_remains_registered_as_its_own_top_level_tab(): void {
		$registry = Plugin::instance()->hub_tab_registry();
		$ids      = array_map( static fn( $tab ) => $tab->id(), $registry->all() );

		foreach ( array( 'rules', 'test-notifications', 'events', 'event-history', 'visitor-tracking', 'operator-inbox', 'operator-identities', 'ai', 'ai-content' ) as $moved_id ) {
			$this->assertNotContains( $moved_id, $ids, "'{$moved_id}' must not remain a registered top-level tab id." );
		}
	}

	public function test_every_moved_screen_is_reachable_through_its_new_area(): void {
		$registry = Plugin::instance()->hub_tab_registry();

		$cases = array(
			'notifications-activity' => array( 'rules', 'test-notifications', 'events', 'event-history', 'visitor-tracking' ),
			'conversations'          => array( 'operator-inbox', 'operator-identities' ),
			'ai-hub'                 => array( 'ai', 'ai-content' ),
		);

		foreach ( $cases as $area_id => $section_ids ) {
			foreach ( $section_ids as $section_id ) {
				$_GET['section'] = $section_id;

				ob_start();
				$registry->get( $area_id )->render();
				$html = ob_get_clean();

				$this->assertStringContainsString( 'nav-tab-active" aria-current="page"', $html, "Area '{$area_id}' section '{$section_id}' did not render an active secondary tab." );
			}
		}

		unset( $_GET['section'] );
	}

	/**
	 * Daily operations summary and Threshold alerts are inline sections of
	 * the Notifications screen (RuleBuilderPage), not separate tabs — they
	 * must still be reachable, unduplicated, once 'rules' is a section of
	 * "Notifications & activity" rather than its own top-level tab.
	 */
	public function test_daily_operations_summary_and_threshold_alerts_remain_reachable_via_the_notifications_section(): void {
		$registry        = Plugin::instance()->hub_tab_registry();
		$_GET['section'] = 'rules';

		ob_start();
		$registry->get( 'notifications-activity' )->render();
		$html = ob_get_clean();
		unset( $_GET['section'] );

		$this->assertStringContainsString( 'Daily operations summary', $html );
		$this->assertStringContainsString( 'Threshold alerts', $html );
	}
}
