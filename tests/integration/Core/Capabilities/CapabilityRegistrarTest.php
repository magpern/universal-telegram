<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Core\Capabilities;

use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;
use UniversalTelegram\Core\Lifecycle\Activator;
use WP_UnitTestCase;

final class CapabilityRegistrarTest extends WP_UnitTestCase {

	public function test_administrator_holds_the_capability_immediately_after_activation(): void {
		$role = get_role( 'administrator' );
		$role->remove_cap( CapabilityRegistrar::MANAGE );
		$role->remove_cap( CapabilityRegistrar::MANAGE_AUTOMATIONS );

		( new Activator() )->activate( false );

		$this->assertTrue( get_role( 'administrator' )->has_cap( CapabilityRegistrar::MANAGE ) );
		$this->assertTrue( get_role( 'administrator' )->has_cap( CapabilityRegistrar::MANAGE_AUTOMATIONS ) );

		$role->remove_cap( CapabilityRegistrar::MANAGE );
		$role->remove_cap( CapabilityRegistrar::MANAGE_AUTOMATIONS );
	}

	public function test_a_role_granted_no_such_capability_does_not_hold_it(): void {
		$role = get_role( 'subscriber' );

		$this->assertFalse( $role->has_cap( CapabilityRegistrar::MANAGE ) );
		$this->assertFalse( $role->has_cap( CapabilityRegistrar::MANAGE_AUTOMATIONS ) );
	}

	public function test_revoke_removes_every_capability_from_every_role(): void {
		$registrar = new CapabilityRegistrar();
		$registrar->grant_to_administrator();

		$this->assertTrue( get_role( 'administrator' )->has_cap( CapabilityRegistrar::MANAGE ) );
		$this->assertTrue( get_role( 'administrator' )->has_cap( CapabilityRegistrar::MANAGE_AUTOMATIONS ) );

		$registrar->revoke_from_all_roles();

		$this->assertFalse( get_role( 'administrator' )->has_cap( CapabilityRegistrar::MANAGE ) );
		$this->assertFalse( get_role( 'administrator' )->has_cap( CapabilityRegistrar::MANAGE_AUTOMATIONS ) );
	}
}
