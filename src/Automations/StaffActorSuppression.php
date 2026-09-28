<?php
/**
 * Suppression of notifications caused by logged-in staff.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Automations;

use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;
use UniversalTelegram\Core\Configuration\Settings;

/**
 * When the `suppress_staff_actor_notifications` setting is on, events
 * raised during a request made by a logged-in manager (shop_manager) or
 * support operator (holds MANAGE_CONVERSATIONS without being a site
 * administrator) send no Telegram notification. The event is still
 * recorded in the history projection; only rule dispatch is skipped.
 * Requests with no logged-in user (cron, webhooks, visitors) are never
 * suppressed.
 */
class StaffActorSuppression {

	public const REASON_CODE = 'skipped_staff_actor';

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Plugin settings.
	 */
	public function __construct( private readonly Settings $settings ) {}

	/**
	 * Whether the current request's user is staff whose actions must not notify.
	 */
	public function is_active(): bool {
		$values = $this->settings->get();

		if ( empty( $values['suppress_staff_actor_notifications'] ) ) {
			return false;
		}

		$user = wp_get_current_user();

		if ( ! $user->exists() ) {
			return false;
		}

		if ( in_array( 'shop_manager', (array) $user->roles, true ) ) {
			return true;
		}

		return user_can( $user, CapabilityRegistrar::MANAGE_CONVERSATIONS ) && ! user_can( $user, 'manage_options' );
	}
}
