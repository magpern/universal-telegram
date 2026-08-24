<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Administration\Hub;

use UniversalTelegram\Administration\Automations\NotificationTesterPage;
use UniversalTelegram\Administration\Hub\HubPage;
use UniversalTelegram\Administration\Hub\Tab;
use UniversalTelegram\Administration\Hub\TabRegistry;
use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;
use WP_UnitTestCase;

final class HubPageTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();

		// The test bootstrap loads the plugin as an MU-plugin, bypassing
		// WordPress' real activation flow, so the capability Activator
		// would normally grant is never actually granted here.
		( new CapabilityRegistrar() )->grant_to_administrator();
		unset( $_GET['tab'] );
	}

	protected function tearDown(): void {
		unset( $_GET['tab'] );
		parent::tearDown();
	}

	private function make_registry(): TabRegistry {
		$registry = new TabRegistry();
		$registry->register(
			new Tab(
				'overview',
				'Overview',
				CapabilityRegistrar::MANAGE,
				static function (): void {
					echo 'overview-content';
				}
			)
		);
		$registry->register(
			new Tab(
				'events',
				'Events',
				CapabilityRegistrar::MANAGE_AUTOMATIONS,
				static function (): void {
					echo 'events-content';
				}
			)
		);

		return $registry;
	}

	public function test_an_absent_tab_value_resolves_to_the_default_tab(): void {
		$page = new HubPage( $this->make_registry() );

		$this->assertSame( 'overview', $page->resolve_tab_id() );
	}

	public function test_an_unknown_tab_value_resolves_to_the_default_tab(): void {
		$_GET['tab'] = 'does-not-exist';
		$page        = new HubPage( $this->make_registry() );

		$this->assertSame( 'overview', $page->resolve_tab_id() );
	}

	public function test_a_known_tab_value_resolves_to_itself(): void {
		$_GET['tab'] = 'events';
		$page        = new HubPage( $this->make_registry() );

		$this->assertSame( 'events', $page->resolve_tab_id() );
	}

	public function test_an_unknown_tab_renders_the_default_tabs_content_not_an_error(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		$_GET['tab'] = 'does-not-exist';

		ob_start();
		( new HubPage( $this->make_registry() ) )->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'overview-content', $output );
		$this->assertStringNotContainsString( 'events-content', $output );
	}

	public function test_a_known_tab_without_the_required_capability_is_denied_by_wordpress_itself(): void {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );
		$_GET['tab'] = 'events';

		$this->expectException( \WPDieException::class );
		( new HubPage( $this->make_registry() ) )->render();
	}

	public function test_a_denied_tab_never_renders_its_content(): void {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );
		$_GET['tab'] = 'events';

		ob_start();
		try {
			( new HubPage( $this->make_registry() ) )->render();
		} catch ( \WPDieException $exception ) {
			$this->assertNotNull( $exception, 'Expected wp_die() denial before any content was emitted.' );
		}
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'events-content', $output );
	}

	public function test_the_active_tab_carries_the_active_class_and_aria_current(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		$_GET['tab'] = 'events';

		ob_start();
		( new HubPage( $this->make_registry() ) )->render();
		$output = ob_get_clean();

		$this->assertMatchesRegularExpression( '/nav-tab-active" aria-current="page">Events</', $output );
		$this->assertStringNotContainsString( 'nav-tab-active" aria-current="page">Overview<', $output );
	}

	private function make_registry_with_test_notifications_tab(): TabRegistry {
		$registry = $this->make_registry();
		$registry->register(
			new Tab(
				NotificationTesterPage::TAB_ID,
				'Test notifications',
				CapabilityRegistrar::MANAGE_AUTOMATIONS,
				static function (): void {
					echo 'test-notifications-content';
				}
			)
		);

		return $registry;
	}

	/**
	 * M08.2 plan §4/§6: a bookmarked `?tab=simulator` URL from before the
	 * Simulator tab was renamed must keep landing on its own content, not
	 * silently fall back to the default Overview tab.
	 */
	public function test_the_legacy_simulator_tab_id_resolves_to_test_notifications(): void {
		$_GET['tab'] = 'simulator';
		$page        = new HubPage( $this->make_registry_with_test_notifications_tab() );

		$this->assertSame( NotificationTesterPage::TAB_ID, $page->resolve_tab_id() );
	}

	public function test_a_legacy_simulator_bookmark_renders_the_test_notifications_tabs_content_not_the_default(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		$_GET['tab'] = 'simulator';

		ob_start();
		( new HubPage( $this->make_registry_with_test_notifications_tab() ) )->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'test-notifications-content', $output );
		$this->assertStringNotContainsString( 'overview-content', $output );
	}
}
