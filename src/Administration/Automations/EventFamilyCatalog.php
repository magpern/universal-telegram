<?php
/**
 * Plain-language event family groupings.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Administration\Automations;

/**
 * Grouping only, derived from the existing event_type list — no Registry
 * change (M08.1 plan "Friendly labels"). Relocated verbatim out of RuleBuilderPage's own private
 * const (M08.2 plan §4) so the friendly event picker's grouping is a
 * single source of truth shared by the rule builder and the notification
 * tester, rather than duplicated data.
 */
final class EventFamilyCatalog {

	public const INTEGRATION_WOOCOMMERCE          = 'woocommerce';
	public const INTEGRATION_FLUENT_CONTACT_INBOX = 'fluent_contact_inbox';

	/**
	 * The event families themselves, keyed by family id.
	 *
	 * @var array<string, array{label: string, requires_integration: string|null, event_types: array<int, string>}>
	 */
	private const FAMILIES = array(
		'website_and_users'  => array(
			'label'                => 'Website and users',
			'requires_integration' => null,
			'event_types'          => array(
				'wordpress.login_succeeded',
				'wordpress.admin_login',
				'wordpress.login_failed',
				'wordpress.user_registered',
				'wordpress.user_role_changed',
				'wordpress.password_reset',
				'wordpress.post_published',
				'wordpress.comment_submitted',
				'wordpress.plugin_activated',
				'wordpress.plugin_deactivated',
				'wordpress.update_available',
				'wordpress.update_completed',
			),
		),
		'store_orders'       => array(
			'label'                => 'Store orders and payments',
			'requires_integration' => self::INTEGRATION_WOOCOMMERCE,
			'event_types'          => array(
				'woocommerce.order_created',
				'woocommerce.order_status_changed',
				'woocommerce.payment_completed',
				'woocommerce.order_failed',
				'woocommerce.order_cancelled',
				'woocommerce.refund_created',
			),
		),
		'stock_and_checkout' => array(
			'label'                => 'Stock and checkout',
			'requires_integration' => self::INTEGRATION_WOOCOMMERCE,
			'event_types'          => array(
				'woocommerce.stock_threshold_crossed',
				'woocommerce.cart_item_added',
				'woocommerce.coupon_applied',
				'woocommerce.coupon_rejected',
				'woocommerce.checkout_validation_failed',
			),
		),
		'website_health'     => array(
			'label'                => 'Website health',
			'requires_integration' => null,
			'event_types'          => array(
				'wordpress.scheduled_task_failed',
				'wordpress.rest_request_failed',
				'wordpress.email_sending_failed',
				'wordpress.fatal_error',
			),
		),
		'support_tickets'    => array(
			'label'                => 'Support tickets and contact requests',
			'requires_integration' => self::INTEGRATION_FLUENT_CONTACT_INBOX,
			'event_types'          => array(
				'fluent_contact_inbox.contact_request_submitted',
				'fluent_contact_inbox.ticket_created',
				'fluent_contact_inbox.ticket_reply_received',
			),
		),
	);

	/**
	 * Every event family, keyed by family id, in display order.
	 *
	 * @return array<string, array{label: string, requires_integration: string|null, event_types: array<int, string>}>
	 */
	public static function families(): array {
		return self::FAMILIES;
	}

	/**
	 * Whether a family's required integration (if any) is active.
	 *
	 * @param array{label: string, requires_integration: string|null, event_types: array<int, string>} $family              The family.
	 * @param array<string, bool>                                                                      $active_integrations Integration key => active.
	 *
	 * @return bool
	 */
	public static function is_family_available( array $family, array $active_integrations ): bool {
		$required = $family['requires_integration'];

		return null === $required || ( $active_integrations[ $required ] ?? false );
	}

	/**
	 * Plain-language explanation shown next to a family whose integration is inactive.
	 *
	 * @param string $integration The integration key.
	 *
	 * @return string
	 */
	public static function unavailable_notice( string $integration ): string {
		if ( self::INTEGRATION_FLUENT_CONTACT_INBOX === $integration ) {
			return __( 'Requires the Fluent IMAP Support Desk plugin (2.1.0 or newer), which is not currently active on this site.', 'universal-telegram' );
		}

		return __( 'Requires WooCommerce, which is not currently active on this site.', 'universal-telegram' );
	}
}
