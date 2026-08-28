<?php
/**
 * Operator-facing labels for the event catalog browser.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Administration\Automations;

/**
 * Maps technical event types and schema field paths to plain-language
 * labels for the Events admin tab. Technical identifiers remain visible
 * alongside these labels for rule authoring and history filtering.
 */
final class EventCatalogLabels {

	/**
	 * Plain-language labels, keyed by technical event type.
	 *
	 * @var array<string, string>
	 */
	private const EVENT_TYPE_LABELS = array(
		'wordpress.login_succeeded'              => 'Successful user login',
		'wordpress.admin_login'                  => 'Administrator login',
		'wordpress.login_failed'                 => 'Failed login attempt',
		'wordpress.user_registered'              => 'New user registered',
		'wordpress.user_role_changed'            => 'User role changed',
		'wordpress.password_reset'               => 'Password reset',
		'wordpress.post_published'               => 'Post published',
		'wordpress.comment_submitted'            => 'Comment submitted',
		'wordpress.plugin_activated'             => 'Plugin activated',
		'wordpress.plugin_deactivated'           => 'Plugin deactivated',
		'wordpress.update_available'             => 'WordPress update available',
		'wordpress.update_completed'             => 'WordPress update completed',
		'wordpress.scheduled_task_failed'        => 'Scheduled task failed',
		'wordpress.rest_request_failed'          => 'Website API request failed',
		'wordpress.email_sending_failed'         => 'Email sending failed',
		'wordpress.fatal_error'                  => 'Website fatal error',
		'woocommerce.order_created'              => 'New order created',
		'woocommerce.order_status_changed'       => 'Order status changed',
		'woocommerce.payment_completed'          => 'Order payment completed',
		'woocommerce.order_failed'               => 'Order failed',
		'woocommerce.order_cancelled'            => 'Order cancelled',
		'woocommerce.refund_created'             => 'Refund created',
		'woocommerce.stock_threshold_crossed'    => 'Stock level reached threshold',
		'woocommerce.cart_item_added'            => 'Product added to cart',
		'woocommerce.coupon_applied'             => 'Coupon applied',
		'woocommerce.coupon_rejected'            => 'Coupon rejected',
		'woocommerce.checkout_validation_failed' => 'Checkout validation failed',
	);

	/**
	 * Plain-language labels, keyed by dot-notation schema field path.
	 *
	 * @var array<string, string>
	 */
	private const FIELD_LABELS = array(
		'actor.user_id'              => 'User account ID',
		'actor.user_login'           => 'Username',
		'context.username'           => 'Attempted username',
		'subject.user_id'            => 'User account ID',
		'subject.username'           => 'Username',
		'subject.name'               => 'Name',
		'subject.email'              => 'Email address',
		'subject.country'            => 'Country',
		'subject.region'             => 'Region',
		'payload.new_role'           => 'New user role',
		'payload.old_roles_csv'      => 'Previous user roles',
		'subject.post_id'            => 'Post ID',
		'payload.post_type'          => 'Content type',
		'subject.comment_id'         => 'Comment ID',
		'payload.plugin'             => 'Plugin',
		'payload.network_wide'       => 'Network-wide activation',
		'payload.component'          => 'Update component',
		'payload.new_version'        => 'New version',
		'payload.type'               => 'Update type',
		'payload.action'             => 'Update action',
		'payload.action_id'          => 'Scheduled task ID',
		'payload.group'              => 'Scheduled task group',
		'payload.hook'               => 'Scheduled task name',
		'payload.route'              => 'API route',
		'payload.status'             => 'HTTP status code',
		'payload.error_code'         => 'Error code',
		'payload.error_type'         => 'Error type',
		'payload.location_hash'      => 'Error location reference',
		'subject.order_id'           => 'Order ID',
		'context.order_status'       => 'Order status',
		'context.storage_backend'    => 'Order storage method',
		'payload.order_total'        => 'Order total',
		'payload.currency'           => 'Currency',
		'payload.item_count'         => 'Number of items',
		'payload.status_from'        => 'Previous order status',
		'payload.status_to'          => 'New order status',
		'context.has_transaction_id' => 'Payment transaction recorded',
		'subject.refund_id'          => 'Refund ID',
		'payload.refund_amount'      => 'Refund amount',
		'subject.product_id'         => 'Product ID',
		'payload.product_name'       => 'Product name',
		'payload.stock_quantity'     => 'Stock quantity',
		'payload.product_sku'        => 'Product SKU',
		'payload.quantity'           => 'Quantity added',
		'payload.variation_id'       => 'Product variation ID',
		'payload.cart_total'         => 'Cart total',
		'subject.coupon_code'        => 'Coupon code',
		'payload.error_codes_csv'    => 'Checkout error codes',
		'context.checkout_type'      => 'Checkout type',
	);

	/**
	 * Plain-language label for one event type.
	 *
	 * @param string $event_type The technical event type.
	 *
	 * @return string
	 */
	public static function event_type_label( string $event_type ): string {
		return self::EVENT_TYPE_LABELS[ $event_type ] ?? $event_type;
	}

	/**
	 * Plain-language label for one schema field path.
	 *
	 * @param string $field_path The dot-notation field path.
	 *
	 * @return string
	 */
	public static function field_label( string $field_path ): string {
		if ( isset( self::FIELD_LABELS[ $field_path ] ) ) {
			return self::FIELD_LABELS[ $field_path ];
		}

		return self::humanize_field_path( $field_path );
	}

	/**
	 * Whether an explicit admin label exists for an event type.
	 *
	 * @param string $event_type The technical event type.
	 *
	 * @return bool
	 */
	public static function has_event_type_label( string $event_type ): bool {
		return isset( self::EVENT_TYPE_LABELS[ $event_type ] );
	}

	/**
	 * Whether an explicit admin label exists for a schema field path.
	 *
	 * @param string $field_path The dot-notation field path.
	 *
	 * @return bool
	 */
	public static function has_field_label( string $field_path ): bool {
		return isset( self::FIELD_LABELS[ $field_path ] );
	}

	/**
	 * Derives a readable fallback from the last segment of a field path.
	 *
	 * @param string $field_path The dot-notation field path.
	 *
	 * @return string
	 */
	private static function humanize_field_path( string $field_path ): string {
		$segment = substr( $field_path, (int) strrpos( $field_path, '.' ) + 1 );

		return ucwords( str_replace( '_', ' ', $segment ) );
	}
}
