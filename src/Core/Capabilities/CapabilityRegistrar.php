<?php
/**
 * Capability grant and revoke lifecycle.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Core\Capabilities;

/**
 * Owns the plugin's one WordPress capability, granted to the
 * administrator role on activation and revoked from every role
 * unconditionally on uninstall (docs/adr/0010). Later milestones extend
 * this same grant-and-revoke pattern with their own capability constants.
 */
final class CapabilityRegistrar {

	public const MANAGE = 'universal_telegram_manage';

	/**
	 * Grants the capability to the administrator role.
	 */
	public function grant_to_administrator(): void {
		$role = get_role( 'administrator' );

		if ( null !== $role ) {
			$role->add_cap( self::MANAGE );
		}
	}

	/**
	 * Revokes the capability from every role, unconditionally.
	 */
	public function revoke_from_all_roles(): void {
		$wp_roles = wp_roles();

		// WP_Roles::remove_cap() updates only its own internal $roles
		// array (and the DB option); it does not sync the capabilities
		// array already held by an instantiated WP_Role object. Calling
		// WP_Role::remove_cap() on each role object updates both.
		foreach ( $wp_roles->role_objects as $role ) {
			$role->remove_cap( self::MANAGE );
		}
	}
}
