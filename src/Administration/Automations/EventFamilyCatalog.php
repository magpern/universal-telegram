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
 * change (M08.1 plan "Friendly labels"). `visitor.click` is deliberately
 * excluded from every family (task requirement); it keeps its
 * EventCatalogLabels entry for other read paths (Events tab, history)
 * unaffected. Relocated verbatim out of RuleBuilderPage's own private
 * const (M08.2 plan §4) so the friendly event picker's grouping is a
 * single source of truth shared by the rule builder and the notification
 * tester, rather than duplicated data.
 */
final class EventFamilyCatalog {

	/**
	 * @var array<string, array{label: string, requires_woocommerce: bool, event_types: array<int, string>}>
	 */
	private const FAMILIES = array(
		'website_and_users'  => array(
			'label'                => 'Website and users',
			'requires_woocommerce' => false,
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
			'requires_woocommerce' => true,
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
			'requires_woocommerce' => true,
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
			'requires_woocommerce' => false,
			'event_types'          => array(
				'wordpress.scheduled_task_failed',
				'wordpress.rest_request_failed',
				'wordpress.email_sending_failed',
				'wordpress.fatal_error',
			),
		),
		'visitor_activity'   => array(
			'label'                => 'Visitor activity',
			'requires_woocommerce' => false,
			'event_types'          => array(
				'visitor.session_started',
				'visitor.page_viewed',
				'visitor.navigation',
				'visitor.search_performed',
				'visitor.javascript_error',
				'visitor.product_viewed',
				'visitor.add_to_cart_intent',
				'visitor.checkout_started_intent',
			),
		),
	);

	/**
	 * Every event family, keyed by family id, in display order.
	 *
	 * @return array<string, array{label: string, requires_woocommerce: bool, event_types: array<int, string>}>
	 */
	public static function families(): array {
		return self::FAMILIES;
	}
}
