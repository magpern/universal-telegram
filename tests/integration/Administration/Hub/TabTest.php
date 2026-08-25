<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Administration\Hub;

use UniversalTelegram\Administration\Hub\Tab;
use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;
use WP_UnitTestCase;

/**
 * M08.2 navigation addendum: Tab gains an optional accessible() override,
 * used only by the three grouped-navigation area tabs. Every pre-existing
 * call site omits it, so is_accessible() must remain byte-identical to a
 * direct current_user_can($capability) call for them.
 */
final class TabTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();

		( new CapabilityRegistrar() )->grant_to_administrator();
	}

	public function test_without_an_override_is_accessible_matches_current_user_can(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$tab = new Tab( 'x', 'X', CapabilityRegistrar::MANAGE_CONVERSATIONS, static function (): void {} );

		$this->assertTrue( $tab->is_accessible() );
		$this->assertSame( current_user_can( CapabilityRegistrar::MANAGE_CONVERSATIONS ), $tab->is_accessible() );

		// A role-inherited cap (CapabilityRegistrar grants to the
		// 'administrator' role, not per-user) can only be denied by
		// removing it from the role itself — WP_User::remove_cap() only
		// ever removes a directly-assigned user meta cap and cannot
		// override one the user still holds via their role. The already-
		// instantiated current-user object also caches its allcaps at
		// construction time (wp_set_current_user() is a no-op for the same
		// user id), so get_role_caps() must be called to recompute them in
		// place.
		get_role( 'administrator' )->remove_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );
		wp_get_current_user()->get_role_caps();

		$this->assertFalse( $tab->is_accessible() );
	}

	public function test_without_an_override_has_no_accessibility_override(): void {
		$tab = new Tab( 'x', 'X', CapabilityRegistrar::MANAGE, static function (): void {} );

		$this->assertFalse( $tab->has_accessibility_override() );
	}

	public function test_a_supplied_override_takes_precedence_over_the_capability_string(): void {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		// A capability string this viewer certainly lacks, but the
		// override always returns true regardless.
		$tab = new Tab(
			'x',
			'X',
			CapabilityRegistrar::MANAGE,
			static function (): void {},
			static fn(): bool => true
		);

		$this->assertTrue( $tab->has_accessibility_override() );
		$this->assertTrue( $tab->is_accessible() );
	}
}
