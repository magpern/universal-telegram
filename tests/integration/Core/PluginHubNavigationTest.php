<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Core;

use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;
use UniversalTelegram\Core\Plugin;
use WP_UnitTestCase;

/**
 * ADR-0044 (transport/adapter only): the real plugin-wired hub tab registry
 * registers only the transport + notifications + adapter tabs. The
 * Conversations and AI grouped areas, and the Visitor Tracking screen, are
 * gone with the legacy chat.
 */
final class PluginHubNavigationTest extends WP_UnitTestCase {
	protected function setUp(): void {
		parent::setUp();

		( new CapabilityRegistrar() )->grant_to_administrator();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function test_the_hub_registers_exactly_the_transport_only_tabs_in_order(): void {
		$registry = Plugin::instance()->hub_tab_registry();
		$this->assertNotNull( $registry );

		$ids = array_map( static fn( $tab ) => $tab->id(), $registry->all() );

		$this->assertSame(
			array(
				'overview',
				'bots',
				'notifications-activity',
				'settings',
				'diagnostics',
				'support-chat-adapter',
				'support-chat-pairing',
			),
			$ids
		);
	}

	public function test_no_legacy_chat_screen_is_registered_anywhere(): void {
		$registry = Plugin::instance()->hub_tab_registry();
		$ids      = array_map( static fn( $tab ) => $tab->id(), $registry->all() );

		foreach ( array( 'conversations', 'ai-hub', 'ai', 'ai-content', 'operator-inbox', 'operator-identities', 'visitor-tracking' ) as $removed_id ) {
			$this->assertNotContains( $removed_id, $ids, "'{$removed_id}' must not be a registered tab id after ADR-0044." );
		}
	}

	public function test_notifications_sections_are_reachable_through_their_area(): void {
		$registry = Plugin::instance()->hub_tab_registry();

		foreach ( array( 'rules', 'test-notifications', 'events', 'event-history' ) as $section_id ) {
			$_GET['section'] = $section_id;

			ob_start();
			$registry->get( 'notifications-activity' )->render();
			$html = ob_get_clean();

			$this->assertStringContainsString( 'nav-tab-active" aria-current="page"', $html, "section '{$section_id}' did not render an active secondary tab." );
		}

		unset( $_GET['section'] );
	}

	public function test_operational_alerts_remain_reachable_via_the_notifications_section(): void {
		$registry        = Plugin::instance()->hub_tab_registry();
		$_GET['section'] = 'rules';

		ob_start();
		$registry->get( 'notifications-activity' )->render();
		$html = ob_get_clean();
		unset( $_GET['section'] );

		$this->assertStringContainsString( 'Operational alerts', $html );
		$this->assertStringContainsString( 'Threshold alerts', $html );
		$this->assertStringNotContainsString( 'Daily operations summary', $html );
	}
}
