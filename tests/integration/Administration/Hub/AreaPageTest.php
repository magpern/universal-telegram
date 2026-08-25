<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Administration\Hub;

use UniversalTelegram\Administration\Hub\AreaPage;
use UniversalTelegram\Administration\Hub\Tab;
use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;
use WP_UnitTestCase;

/**
 * M08.2 navigation addendum: AreaPage resolves the requested `section`
 * against its own children, always deferring to each section's own
 * capability, and safely falling back to the first accessible section
 * rather than an error or a silently-wrong render.
 */
final class AreaPageTest extends WP_UnitTestCase {

	protected function tearDown(): void {
		unset( $_GET['section'] );
		parent::tearDown();
	}

	private function area( ?string $second_capability = null ): AreaPage {
		return new AreaPage(
			'demo-area',
			'Demo area',
			array(
				new Tab(
					'first',
					'First',
					CapabilityRegistrar::MANAGE,
					static function (): void {
						echo 'first-content';
					}
				),
				new Tab(
					'second',
					'Second',
					$second_capability ?? CapabilityRegistrar::MANAGE,
					static function (): void {
						echo 'second-content';
					}
				),
			)
		);
	}

	public function test_a_requested_known_section_renders_its_own_content(): void {
		( new CapabilityRegistrar() )->grant_to_administrator();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_GET['section'] = 'second';

		ob_start();
		$this->area()->render_tab_content();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'second-content', $html );
		$this->assertStringNotContainsString( 'first-content', $html );
	}

	public function test_a_missing_section_falls_back_to_the_first_accessible_one(): void {
		( new CapabilityRegistrar() )->grant_to_administrator();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		unset( $_GET['section'] );

		ob_start();
		$this->area()->render_tab_content();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'first-content', $html );
	}

	public function test_an_unknown_section_falls_back_to_the_first_accessible_one(): void {
		( new CapabilityRegistrar() )->grant_to_administrator();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_GET['section'] = 'does-not-exist';

		ob_start();
		$this->area()->render_tab_content();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'first-content', $html );
	}

	public function test_a_known_but_currently_inaccessible_section_falls_back_to_the_first_accessible_one(): void {
		$_GET['section'] = 'second';

		$area = $this->area( CapabilityRegistrar::MANAGE_CONVERSATIONS );
		( new CapabilityRegistrar() )->grant_to_administrator();
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		// Deny only the 'second' section's own capability, keep 'first's.
		// A role-inherited cap can only be denied by removing it from the
		// role itself (see TabTest for why per-user remove_cap() cannot); the
		// already-instantiated current user must recompute its cached
		// allcaps (wp_set_current_user() is a no-op for the same user id).
		get_role( 'administrator' )->remove_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );
		wp_get_current_user()->get_role_caps();

		ob_start();
		$area->render_tab_content();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'first-content', $html );
		$this->assertStringNotContainsString( 'second-content', $html );
	}

	public function test_zero_accessible_sections_denies_with_wp_die(): void {
		$area = new AreaPage(
			'demo-area',
			'Demo area',
			array(
				new Tab(
					'first',
					'First',
					CapabilityRegistrar::MANAGE_CONVERSATIONS,
					static function (): void {
						echo 'first-content';
					}
				),
			)
		);

		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$this->expectException( \WPDieException::class );
		$area->render_tab_content();
	}

	public function test_is_accessible_reflects_whether_any_section_passes(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		( new CapabilityRegistrar() )->grant_to_administrator();

		$this->assertTrue( $this->area()->is_accessible() );

		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$this->assertFalse( $this->area()->is_accessible() );
	}

	public function test_the_secondary_nav_lists_only_accessible_sections_with_correct_active_state(): void {
		( new CapabilityRegistrar() )->grant_to_administrator();
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		// A role-inherited cap can only be denied by removing it from the
		// role itself (see TabTest for why per-user remove_cap() cannot); the
		// already-instantiated current user must recompute its cached
		// allcaps (wp_set_current_user() is a no-op for the same user id).
		get_role( 'administrator' )->remove_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );
		wp_get_current_user()->get_role_caps();

		$_GET['section'] = 'first';

		ob_start();
		$this->area( CapabilityRegistrar::MANAGE_CONVERSATIONS )->render_tab_content();
		$html = ob_get_clean();

		$this->assertStringContainsString( '>First</a>', $html );
		$this->assertStringNotContainsString( '>Second</a>', $html );
		$this->assertMatchesRegularExpression( '/nav-tab-active" aria-current="page">First</', $html );
	}
}
