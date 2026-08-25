<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Administration\Hub;

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
		unset( $_GET['tab'], $_GET['section'] );
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
				'reports',
				'Reports',
				CapabilityRegistrar::MANAGE_AUTOMATIONS,
				static function (): void {
					echo 'reports-content';
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
		$_GET['tab'] = 'reports';
		$page        = new HubPage( $this->make_registry() );

		$this->assertSame( 'reports', $page->resolve_tab_id() );
	}

	public function test_an_unknown_tab_renders_the_default_tabs_content_not_an_error(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		$_GET['tab'] = 'does-not-exist';

		ob_start();
		( new HubPage( $this->make_registry() ) )->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'overview-content', $output );
		$this->assertStringNotContainsString( 'reports-content', $output );
	}

	public function test_a_known_tab_without_the_required_capability_is_denied_by_wordpress_itself(): void {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );
		$_GET['tab'] = 'reports';

		$this->expectException( \WPDieException::class );
		( new HubPage( $this->make_registry() ) )->render();
	}

	public function test_a_denied_tab_never_renders_its_content(): void {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );
		$_GET['tab'] = 'reports';

		ob_start();
		try {
			( new HubPage( $this->make_registry() ) )->render();
		} catch ( \WPDieException $exception ) {
			$this->assertNotNull( $exception, 'Expected wp_die() denial before any content was emitted.' );
		}
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'reports-content', $output );
	}

	public function test_the_active_tab_carries_the_active_class_and_aria_current(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		$_GET['tab'] = 'reports';

		ob_start();
		( new HubPage( $this->make_registry() ) )->render();
		$output = ob_get_clean();

		$this->assertMatchesRegularExpression( '/nav-tab-active" aria-current="page">Reports</', $output );
		$this->assertStringNotContainsString( 'nav-tab-active" aria-current="page">Overview<', $output );
	}

	/**
	 * A registry with one synthetic "notifications-activity"-shaped area
	 * tab whose own render echoes the requested `section`, so a test can
	 * observe exactly which section HubPage's alias resolution selected —
	 * without depending on the real Plugin wiring (covered separately by
	 * PluginHubNavigationTest).
	 */
	private function make_registry_with_area_tab( string $area_id ): TabRegistry {
		$registry = $this->make_registry();
		$registry->register(
			new Tab(
				$area_id,
				$area_id,
				CapabilityRegistrar::MANAGE,
				static function () use ( $area_id ): void {
					$section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( (string) $_GET['section'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					echo esc_html( $area_id . '-content:' . $section );
				}
			)
		);

		return $registry;
	}

	/**
	 * Every legacy id the M08.2 navigation addendum's LEGACY_TAB_ALIASES
	 * table must still resolve — the original M08.2 `simulator` alias plus
	 * every screen moved into a grouped area (M08.2 navigation addendum
	 * plan §"Compatibility mechanism").
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function legacy_alias_provider(): array {
		return array(
			'simulator (M08.2 original alias)' => array( 'simulator', 'notifications-activity', 'test-notifications' ),
			'rules'                            => array( 'rules', 'notifications-activity', 'rules' ),
			'test-notifications'               => array( 'test-notifications', 'notifications-activity', 'test-notifications' ),
			'events'                           => array( 'events', 'notifications-activity', 'events' ),
			'event-history'                    => array( 'event-history', 'notifications-activity', 'event-history' ),
			'visitor-tracking'                 => array( 'visitor-tracking', 'notifications-activity', 'visitor-tracking' ),
			'operator-inbox'                   => array( 'operator-inbox', 'conversations', 'operator-inbox' ),
			'operator-identities'              => array( 'operator-identities', 'conversations', 'operator-identities' ),
			'ai'                               => array( 'ai', 'ai-hub', 'ai' ),
			'ai-content'                       => array( 'ai-content', 'ai-hub', 'ai-content' ),
		);
	}

	/**
	 * @dataProvider legacy_alias_provider
	 */
	public function test_every_legacy_id_resolves_to_its_new_area_and_section( string $legacy_id, string $expected_area, string $expected_section ): void {
		$_GET['tab'] = $legacy_id;
		$page        = new HubPage( $this->make_registry_with_area_tab( $expected_area ) );

		$this->assertSame( $expected_area, $page->resolve_tab_id() );
		$this->assertSame( $expected_section, $_GET['section'] ?? null ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- test assertion reading the exact value this test itself set via HubPage::resolve_tab_id(), not user input.

		unset( $_GET['section'] );
	}

	/**
	 * @dataProvider legacy_alias_provider
	 */
	public function test_every_legacy_id_renders_its_own_former_content_not_the_default( string $legacy_id, string $expected_area, string $expected_section ): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		$_GET['tab'] = $legacy_id;

		ob_start();
		( new HubPage( $this->make_registry_with_area_tab( $expected_area ) ) )->render();
		$output = ob_get_clean();
		unset( $_GET['section'] );

		$this->assertStringContainsString( $expected_area . '-content:' . $expected_section, $output );
		$this->assertStringNotContainsString( 'overview-content', $output );
	}

	/**
	 * A direct deep link (no legacy id involved) must resolve exactly as
	 * requested, not only through the alias table.
	 */
	public function test_a_direct_area_and_section_deep_link_resolves_as_requested(): void {
		$_GET['tab']     = 'notifications-activity';
		$_GET['section'] = 'event-history';
		$page            = new HubPage( $this->make_registry_with_area_tab( 'notifications-activity' ) );

		$this->assertSame( 'notifications-activity', $page->resolve_tab_id() );
		$this->assertSame( 'event-history', $_GET['section'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- test assertion reading the exact value this test itself set two lines above, not user input.

		unset( $_GET['section'] );
	}

	/**
	 * A parent area tab with a custom accessibility override is hidden
	 * from the nav row entirely when that override fails — unlike a plain
	 * solo tab (test_a_known_tab_without_the_required_capability_is_denied_by_wordpress_itself
	 * above), which is still listed and only denied on click, preserving
	 * that pre-addendum behavior exactly.
	 */
	public function test_an_inaccessible_area_tab_is_not_listed_in_the_nav(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$registry = $this->make_registry();
		$registry->register(
			new Tab(
				'grouped-area',
				'Grouped Area',
				CapabilityRegistrar::MANAGE,
				static function (): void {
					echo 'grouped-area-content';
				},
				static fn(): bool => false
			)
		);

		ob_start();
		( new HubPage( $registry ) )->render();
		$output = ob_get_clean();

		$this->assertStringNotContainsString( '>Grouped Area<', $output );
	}

	/**
	 * The same override, when it passes, keeps the tab listed and
	 * reachable exactly like any other tab.
	 */
	public function test_an_accessible_area_tab_is_listed_in_the_nav_and_gets_the_active_class(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$registry = $this->make_registry();
		$registry->register(
			new Tab(
				'grouped-area',
				'Grouped Area',
				CapabilityRegistrar::MANAGE,
				static function (): void {
					echo 'grouped-area-content';
				},
				static fn(): bool => true
			)
		);
		$_GET['tab'] = 'grouped-area';

		ob_start();
		( new HubPage( $registry ) )->render();
		$output = ob_get_clean();

		$this->assertMatchesRegularExpression( '/nav-tab-active" aria-current="page">Grouped Area</', $output );
		$this->assertStringContainsString( 'grouped-area-content', $output );
	}
}
