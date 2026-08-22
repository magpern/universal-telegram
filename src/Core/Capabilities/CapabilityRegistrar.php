<?php
/**
 * Capability grant and revoke lifecycle.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Core\Capabilities;

/**
 * Owns the plugin's WordPress capabilities, granted to the administrator
 * role on activation and revoked from every role unconditionally on
 * uninstall (docs/adr/0010). M02 adds MANAGE_AUTOMATIONS as a distinct
 * constant, since rule/event configuration is a genuinely distinct
 * authorization need from bot/destination configuration (M02 plan §6.3);
 * manage_options is never substituted for either. M07 adds
 * MANAGE_CONVERSATIONS, the operator self-service capability (inbox,
 * assignment, notes, own availability); administrator-level overrides
 * continue to use the broader, existing MANAGE (docs/adr/0026).
 */
final class CapabilityRegistrar {

	public const MANAGE               = 'universal_telegram_manage';
	public const MANAGE_AUTOMATIONS   = 'universal_telegram_manage_automations';
	public const MANAGE_CONVERSATIONS = 'universal_telegram_manage_conversations';

	/**
	 * Grants every capability to the administrator role.
	 */
	public function grant_to_administrator(): void {
		$role = get_role( 'administrator' );

		if ( null !== $role ) {
			$role->add_cap( self::MANAGE );
			$role->add_cap( self::MANAGE_AUTOMATIONS );
			$role->add_cap( self::MANAGE_CONVERSATIONS );
		}
	}

	/**
	 * Revokes every capability from every role, unconditionally.
	 */
	public function revoke_from_all_roles(): void {
		$wp_roles = wp_roles();

		// WP_Roles::remove_cap() updates only its own internal $roles
		// array (and the DB option); it does not sync the capabilities
		// array already held by an instantiated WP_Role object. Calling
		// WP_Role::remove_cap() on each role object updates both.
		foreach ( $wp_roles->role_objects as $role ) {
			$role->remove_cap( self::MANAGE );
			$role->remove_cap( self::MANAGE_AUTOMATIONS );
			$role->remove_cap( self::MANAGE_CONVERSATIONS );
		}
	}
}
