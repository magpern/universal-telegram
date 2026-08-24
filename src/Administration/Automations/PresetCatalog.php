<?php
/**
 * Notification preset catalog (M08.1 plan "Preset catalogue").
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Administration\Automations;

/**
 * Each preset is a starting configuration for the friendly Add Rule
 * builder, never a rule created or enabled by itself: selecting one fills
 * the builder's own fields, and the administrator must still choose a
 * bot/destination and press Save. Every preset's conditions reference only
 * fields already present in that event type's own
 * Registry::allowed_variable_fields_for() list and fully catalogued in
 * FieldTypeCatalog (enforced by a coverage test, not merely documented
 * here). Messages are professional plain text with no emoji.
 */
final class PresetCatalog {

	/**
	 * Every built-in notification preset.
	 *
	 * @var array<int, array{key: string, title: string, description: string, event_type: string, conditions: array<int, array<string, mixed>>, match_mode: string, message: string, requires_woocommerce: bool}>
	 */
	private const PRESETS = array(
		array(
			'key'                  => 'new_order',
			'title'                => 'New WooCommerce order',
			'description'          => 'Notify when a customer places a new order.',
			'event_type'           => 'woocommerce.order_created',
			'conditions'           => array(),
			'match_mode'           => 'all',
			'message'              => 'New order #{{subject.order_id}} — {{payload.order_total}} {{payload.currency}}.',
			'requires_woocommerce' => true,
		),
		array(
			'key'                  => 'payment_completed',
			'title'                => 'Payment completed',
			'description'          => 'Notify when a customer\'s payment for an order completes.',
			'event_type'           => 'woocommerce.payment_completed',
			'conditions'           => array(),
			'match_mode'           => 'all',
			'message'              => 'Payment received for order #{{subject.order_id}}.',
			'requires_woocommerce' => true,
		),
		array(
			'key'                  => 'order_failed',
			'title'                => 'Order failed',
			'description'          => 'Notify when an order fails, such as a declined payment.',
			'event_type'           => 'woocommerce.order_failed',
			'conditions'           => array(),
			'match_mode'           => 'all',
			'message'              => 'Order #{{subject.order_id}} failed.',
			'requires_woocommerce' => true,
		),
		array(
			'key'                  => 'order_cancelled',
			'title'                => 'Order cancelled',
			'description'          => 'Notify when an order is cancelled.',
			'event_type'           => 'woocommerce.order_cancelled',
			'conditions'           => array(),
			'match_mode'           => 'all',
			'message'              => 'Order #{{subject.order_id}} was cancelled.',
			'requires_woocommerce' => true,
		),
		array(
			'key'                  => 'refund_created',
			'title'                => 'Refund created',
			'description'          => 'Notify when a refund is issued for an order.',
			'event_type'           => 'woocommerce.refund_created',
			'conditions'           => array(),
			'match_mode'           => 'all',
			'message'              => 'Refund issued: {{payload.refund_amount}} for order #{{subject.order_id}}.',
			'requires_woocommerce' => true,
		),
		array(
			'key'                  => 'low_stock',
			'title'                => 'Low-stock alert',
			'description'          => 'Notify when a product\'s stock crosses its low-stock threshold.',
			'event_type'           => 'woocommerce.stock_threshold_crossed',
			'conditions'           => array(),
			'match_mode'           => 'all',
			'message'              => '{{payload.product_sku}} stock is low: {{payload.stock_quantity}} left.',
			'requires_woocommerce' => true,
		),
		array(
			'key'                  => 'checkout_problem',
			'title'                => 'Checkout problem detected',
			'description'          => 'Notify when a customer encounters a checkout validation error.',
			'event_type'           => 'woocommerce.checkout_validation_failed',
			'conditions'           => array(),
			'match_mode'           => 'all',
			'message'              => 'A customer encountered a checkout problem: {{payload.error_codes_csv}}.',
			'requires_woocommerce' => true,
		),
		array(
			'key'                  => 'added_to_cart',
			'title'                => 'Added to cart',
			'description'          => 'Notify when a customer adds a product to their cart.',
			'event_type'           => 'woocommerce.cart_item_added',
			'conditions'           => array(),
			'match_mode'           => 'all',
			'message'              => '{{payload.product_name}} added to cart (quantity: {{payload.quantity}}).',
			'requires_woocommerce' => true,
		),
		array(
			'key'                  => 'admin_login',
			'title'                => 'Successful administrator login',
			'description'          => 'Notify when an administrator account signs in.',
			'event_type'           => 'wordpress.admin_login',
			'conditions'           => array(),
			'match_mode'           => 'all',
			'message'              => 'Administrator login: {{actor.user_login}}.',
			'requires_woocommerce' => false,
		),
		array(
			'key'                  => 'failed_login',
			'title'                => 'Failed login attempt',
			'description'          => 'Notify when a login attempt fails.',
			'event_type'           => 'wordpress.login_failed',
			'conditions'           => array(),
			'match_mode'           => 'all',
			'message'              => 'Failed login attempt for username {{context.username}}.',
			'requires_woocommerce' => false,
		),
		array(
			'key'                  => 'new_user',
			'title'                => 'New user registered',
			'description'          => 'Notify when a new user account is created.',
			'event_type'           => 'wordpress.user_registered',
			'conditions'           => array(),
			'match_mode'           => 'all',
			'message'              => 'New user registered: {{subject.name}} ({{subject.username}}), {{subject.email}}.',
			'requires_woocommerce' => false,
		),
		array(
			'key'                  => 'fatal_error',
			'title'                => 'Website fatal error',
			'description'          => 'Notify when the website encounters a fatal error.',
			'event_type'           => 'wordpress.fatal_error',
			'conditions'           => array(),
			'match_mode'           => 'all',
			'message'              => 'A website error occurred ({{payload.error_type}}).',
			'requires_woocommerce' => false,
		),
		array(
			'key'                  => 'scheduled_task_failed',
			'title'                => 'Scheduled task failed',
			'description'          => 'Notify when a scheduled background task fails.',
			'event_type'           => 'wordpress.scheduled_task_failed',
			'conditions'           => array(),
			'match_mode'           => 'all',
			'message'              => 'Scheduled task failed: {{payload.hook}}.',
			'requires_woocommerce' => false,
		),
		array(
			'key'                  => 'email_failed',
			'title'                => 'Email sending failed',
			'description'          => 'Notify when an outgoing website email fails to send.',
			'event_type'           => 'wordpress.email_sending_failed',
			'conditions'           => array(),
			'match_mode'           => 'all',
			'message'              => 'An outgoing email failed to send.',
			'requires_woocommerce' => false,
		),
		array(
			'key'                  => 'api_request_failed',
			'title'                => 'Website API request failed',
			'description'          => 'Notify when a request to the website\'s own API fails with a server error.',
			'event_type'           => 'wordpress.rest_request_failed',
			'conditions'           => array(
				array(
					'field'    => 'payload.status',
					'operator' => 'at_least',
					'value'    => '500',
				),
			),
			'match_mode'           => 'all',
			'message'              => 'API request failed: {{payload.route}} ({{payload.status}}).',
			'requires_woocommerce' => false,
		),
		array(
			'key'                  => 'visitor_product_viewed',
			'title'                => 'Visitor viewed a product',
			'description'          => 'Notify when a visitor views a product page.',
			'event_type'           => 'visitor.product_viewed',
			'conditions'           => array(),
			'match_mode'           => 'all',
			'message'              => 'A visitor viewed a product.',
			'requires_woocommerce' => true,
		),
		array(
			'key'                  => 'visitor_checkout_started',
			'title'                => 'Visitor started checkout',
			'description'          => 'Notify when a visitor opens checkout.',
			'event_type'           => 'visitor.checkout_started_intent',
			'conditions'           => array(),
			'match_mode'           => 'all',
			'message'              => 'A visitor opened checkout.',
			'requires_woocommerce' => true,
		),
	);

	/**
	 * Every individual preset.
	 *
	 * @return array<int, array{key: string, title: string, description: string, event_type: string, conditions: array<int, array<string, mixed>>, match_mode: string, message: string, requires_woocommerce: bool}>
	 */
	public static function all(): array {
		return self::PRESETS;
	}

	/**
	 * One preset by its key, or null if unknown.
	 *
	 * @param string $key The preset key.
	 *
	 * @return array{key: string, title: string, description: string, event_type: string, conditions: array<int, array<string, mixed>>, match_mode: string, message: string, requires_woocommerce: bool}|null
	 */
	public static function find( string $key ): ?array {
		foreach ( self::PRESETS as $preset ) {
			if ( $preset['key'] === $key ) {
				return $preset;
			}
		}

		return null;
	}

	/**
	 * The three "Store essentials" starter-set presets, offered only when
	 * WooCommerce is active (all three depend on WooCommerce events).
	 *
	 * @return array<int, array{key: string, title: string, description: string, event_type: string, conditions: array<int, array<string, mixed>>, match_mode: string, message: string, requires_woocommerce: bool}>
	 */
	public static function starter_set(): array {
		return array(
			self::find( 'new_order' ),
			self::find( 'order_failed' ),
			self::find( 'low_stock' ),
		);
	}
}
