<?php
/**
 * Explicit, fail-closed field-type metadata for the friendly rule builder.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Administration\Automations;

/**
 * A hand-authored, complete-by-construction map from allowed condition/
 * template field path to its UI type, permitted friendly operators, and a
 * fixed, non-sensitive preview value — plus a fixed choice-option list for
 * `choice`-typed fields. This is the single fail-closed gate for the
 * condition builder and the message field-insert menu (M08.1 plan
 * "Field type metadata"): a field with no complete entry here simply does
 * not appear in either control, regardless of whether
 * Registry::allowed_variable_fields_for() permits it server-side. There is
 * no generic-text fallback — cataloguing a field is a deliberate act, never
 * an automatic side effect of the engine allowing it.
 *
 * Every key here is also a key of EventCatalogLabels::FIELD_LABELS, and
 * label() delegates to EventCatalogLabels::field_label() rather than
 * storing its own copy, so the plain-language label stays single-sourced
 * (M08.1 plan "reuse EventCatalogLabels verbatim; no new label source of
 * truth"); FieldTypeCatalog owns only type/operators/preview/choices, the
 * metadata EventCatalogLabels never carried.
 */
final class FieldTypeCatalog {

	public const TYPE_TEXT    = 'text';
	public const TYPE_NUMBER  = 'number';
	public const TYPE_MONEY   = 'money';
	public const TYPE_BOOLEAN = 'boolean';
	public const TYPE_CHOICE  = 'choice';

	private const TEXT_OPERATORS    = array( 'equals', 'not_equals', 'contains', 'not_contains' );
	private const NUMERIC_OPERATORS = array( 'equals', 'not_equals', 'greater_than', 'less_than', 'at_least', 'at_most' );
	private const BOOLEAN_OPERATORS = array( 'equals', 'not_equals' );
	private const CHOICE_OPERATORS  = array( 'equals', 'not_equals' );

	private const ORDER_STATUS_CHOICES = array(
		'pending'    => 'Pending payment',
		'processing' => 'Processing',
		'on-hold'    => 'On hold',
		'completed'  => 'Completed',
		'cancelled'  => 'Cancelled',
		'refunded'   => 'Refunded',
		'failed'     => 'Failed',
	);

	/**
	 * @var array<string, array{type: string, operators: array<int, string>, preview_value: string, choice_options?: array<string, string>}>
	 */
	private const FIELDS = array(
		'actor.user_id'              => array(
			'type'          => self::TYPE_NUMBER,
			'operators'     => self::NUMERIC_OPERATORS,
			'preview_value' => '42',
		),
		'actor.user_login'           => array(
			'type'          => self::TYPE_TEXT,
			'operators'     => self::TEXT_OPERATORS,
			'preview_value' => 'jsmith',
		),
		'context.username'           => array(
			'type'          => self::TYPE_TEXT,
			'operators'     => self::TEXT_OPERATORS,
			'preview_value' => 'jsmith',
		),
		'subject.user_id'            => array(
			'type'          => self::TYPE_NUMBER,
			'operators'     => self::NUMERIC_OPERATORS,
			'preview_value' => '42',
		),
		'payload.new_role'           => array(
			'type'            => self::TYPE_CHOICE,
			'operators'       => self::CHOICE_OPERATORS,
			'preview_value'   => 'editor',
			'choice_options'  => array(
				'administrator' => 'Administrator',
				'editor'        => 'Editor',
				'author'        => 'Author',
				'contributor'   => 'Contributor',
				'subscriber'    => 'Subscriber',
			),
		),
		'payload.old_roles_csv'      => array(
			'type'          => self::TYPE_TEXT,
			'operators'     => self::TEXT_OPERATORS,
			'preview_value' => 'subscriber',
		),
		'subject.post_id'            => array(
			'type'          => self::TYPE_NUMBER,
			'operators'     => self::NUMERIC_OPERATORS,
			'preview_value' => '123',
		),
		'payload.post_type'          => array(
			'type'          => self::TYPE_TEXT,
			'operators'     => self::TEXT_OPERATORS,
			'preview_value' => 'post',
		),
		'subject.comment_id'         => array(
			'type'          => self::TYPE_NUMBER,
			'operators'     => self::NUMERIC_OPERATORS,
			'preview_value' => '77',
		),
		'payload.plugin'             => array(
			'type'          => self::TYPE_TEXT,
			'operators'     => self::TEXT_OPERATORS,
			'preview_value' => 'akismet/akismet.php',
		),
		'payload.network_wide'       => array(
			'type'          => self::TYPE_BOOLEAN,
			'operators'     => self::BOOLEAN_OPERATORS,
			'preview_value' => 'true',
		),
		'payload.component'         => array(
			'type'          => self::TYPE_TEXT,
			'operators'     => self::TEXT_OPERATORS,
			'preview_value' => 'core',
		),
		'payload.new_version'       => array(
			'type'          => self::TYPE_TEXT,
			'operators'     => self::TEXT_OPERATORS,
			'preview_value' => '6.5.2',
		),
		'payload.type'              => array(
			'type'          => self::TYPE_TEXT,
			'operators'     => self::TEXT_OPERATORS,
			'preview_value' => 'plugin',
		),
		'payload.action'            => array(
			'type'          => self::TYPE_TEXT,
			'operators'     => self::TEXT_OPERATORS,
			'preview_value' => 'update',
		),
		'payload.action_id'         => array(
			'type'          => self::TYPE_TEXT,
			'operators'     => self::TEXT_OPERATORS,
			'preview_value' => 'wc_cleanup_sessions',
		),
		'payload.group'             => array(
			'type'          => self::TYPE_TEXT,
			'operators'     => self::TEXT_OPERATORS,
			'preview_value' => 'woocommerce',
		),
		'payload.hook'              => array(
			'type'          => self::TYPE_TEXT,
			'operators'     => self::TEXT_OPERATORS,
			'preview_value' => 'wc_cleanup_sessions',
		),
		'payload.route'             => array(
			'type'          => self::TYPE_TEXT,
			'operators'     => self::TEXT_OPERATORS,
			'preview_value' => '/wp-json/wc/v3/orders',
		),
		'payload.status'            => array(
			'type'          => self::TYPE_NUMBER,
			'operators'     => self::NUMERIC_OPERATORS,
			'preview_value' => '500',
		),
		'payload.error_code'        => array(
			'type'          => self::TYPE_TEXT,
			'operators'     => self::TEXT_OPERATORS,
			'preview_value' => '500',
		),
		'payload.error_type'        => array(
			'type'          => self::TYPE_TEXT,
			'operators'     => self::TEXT_OPERATORS,
			'preview_value' => 'fatal',
		),
		'payload.location_hash'     => array(
			'type'          => self::TYPE_TEXT,
			'operators'     => self::TEXT_OPERATORS,
			'preview_value' => 'a1b2c3d4',
		),
		'subject.order_id'          => array(
			'type'          => self::TYPE_NUMBER,
			'operators'     => self::NUMERIC_OPERATORS,
			'preview_value' => '1042',
		),
		'context.order_status'      => array(
			'type'           => self::TYPE_CHOICE,
			'operators'      => self::CHOICE_OPERATORS,
			'preview_value'  => 'processing',
			'choice_options' => self::ORDER_STATUS_CHOICES,
		),
		'context.storage_backend'   => array(
			'type'           => self::TYPE_CHOICE,
			'operators'      => self::CHOICE_OPERATORS,
			'preview_value'  => 'hpos',
			'choice_options' => array(
				'hpos'   => 'High-performance order storage',
				'legacy' => 'Legacy post storage',
			),
		),
		'payload.order_total'       => array(
			'type'          => self::TYPE_MONEY,
			'operators'     => self::NUMERIC_OPERATORS,
			'preview_value' => '49.90',
		),
		'payload.currency'          => array(
			'type'          => self::TYPE_TEXT,
			'operators'     => self::TEXT_OPERATORS,
			'preview_value' => 'EUR',
		),
		'payload.item_count'        => array(
			'type'          => self::TYPE_NUMBER,
			'operators'     => self::NUMERIC_OPERATORS,
			'preview_value' => '3',
		),
		'payload.status_from'       => array(
			'type'           => self::TYPE_CHOICE,
			'operators'      => self::CHOICE_OPERATORS,
			'preview_value'  => 'pending',
			'choice_options' => self::ORDER_STATUS_CHOICES,
		),
		'payload.status_to'         => array(
			'type'           => self::TYPE_CHOICE,
			'operators'      => self::CHOICE_OPERATORS,
			'preview_value'  => 'processing',
			'choice_options' => self::ORDER_STATUS_CHOICES,
		),
		'context.has_transaction_id' => array(
			'type'          => self::TYPE_BOOLEAN,
			'operators'     => self::BOOLEAN_OPERATORS,
			'preview_value' => 'true',
		),
		'subject.refund_id'         => array(
			'type'          => self::TYPE_NUMBER,
			'operators'     => self::NUMERIC_OPERATORS,
			'preview_value' => '12',
		),
		'payload.refund_amount'     => array(
			'type'          => self::TYPE_MONEY,
			'operators'     => self::NUMERIC_OPERATORS,
			'preview_value' => '10.00',
		),
		'subject.product_id'        => array(
			'type'          => self::TYPE_NUMBER,
			'operators'     => self::NUMERIC_OPERATORS,
			'preview_value' => '501',
		),
		'payload.stock_quantity'    => array(
			'type'          => self::TYPE_NUMBER,
			'operators'     => self::NUMERIC_OPERATORS,
			'preview_value' => '2',
		),
		'payload.product_sku'       => array(
			'type'          => self::TYPE_TEXT,
			'operators'     => self::TEXT_OPERATORS,
			'preview_value' => 'SKU-001',
		),
		'payload.quantity'          => array(
			'type'          => self::TYPE_NUMBER,
			'operators'     => self::NUMERIC_OPERATORS,
			'preview_value' => '1',
		),
		'payload.variation_id'      => array(
			'type'          => self::TYPE_NUMBER,
			'operators'     => self::NUMERIC_OPERATORS,
			'preview_value' => '0',
		),
		'payload.cart_total'        => array(
			'type'          => self::TYPE_MONEY,
			'operators'     => self::NUMERIC_OPERATORS,
			'preview_value' => '29.90',
		),
		'subject.coupon_code'       => array(
			'type'          => self::TYPE_TEXT,
			'operators'     => self::TEXT_OPERATORS,
			'preview_value' => 'SAVE10',
		),
		'payload.error_codes_csv'   => array(
			'type'          => self::TYPE_TEXT,
			'operators'     => self::TEXT_OPERATORS,
			'preview_value' => 'invalid_email',
		),
		'context.checkout_type'     => array(
			'type'           => self::TYPE_CHOICE,
			'operators'      => self::CHOICE_OPERATORS,
			'preview_value'  => 'block',
			'choice_options' => array(
				'classic' => 'Classic checkout',
				'block'   => 'Block checkout',
			),
		),
		'subject.path'              => array(
			'type'          => self::TYPE_TEXT,
			'operators'     => self::TEXT_OPERATORS,
			'preview_value' => '/shop/',
		),
		'subject.page_type'         => array(
			'type'          => self::TYPE_TEXT,
			'operators'     => self::TEXT_OPERATORS,
			'preview_value' => 'product',
		),
		'subject.from_path'         => array(
			'type'          => self::TYPE_TEXT,
			'operators'     => self::TEXT_OPERATORS,
			'preview_value' => '/cart/',
		),
		'subject.to_path'           => array(
			'type'          => self::TYPE_TEXT,
			'operators'     => self::TEXT_OPERATORS,
			'preview_value' => '/checkout/',
		),
		'payload.result_count'      => array(
			'type'          => self::TYPE_NUMBER,
			'operators'     => self::NUMERIC_OPERATORS,
			'preview_value' => '5',
		),
		'subject.target_key'        => array(
			'type'          => self::TYPE_TEXT,
			'operators'     => self::TEXT_OPERATORS,
			'preview_value' => 'newsletter_signup',
		),
		'payload.error_category'    => array(
			'type'          => self::TYPE_TEXT,
			'operators'     => self::TEXT_OPERATORS,
			'preview_value' => 'network',
		),
	);

	/**
	 * Whether a field is fully catalogued and therefore eligible to appear
	 * in the condition builder or field-insert menu.
	 *
	 * @param string $field_path The dot-notation field path.
	 *
	 * @return bool
	 */
	public static function has( string $field_path ): bool {
		return isset( self::FIELDS[ $field_path ] );
	}

	/**
	 * The field's UI type, or null if uncatalogued.
	 *
	 * @param string $field_path The dot-notation field path.
	 *
	 * @return string|null
	 */
	public static function type( string $field_path ): ?string {
		return self::FIELDS[ $field_path ]['type'] ?? null;
	}

	/**
	 * The friendly label for a catalogued field, delegating to
	 * EventCatalogLabels so the label text has exactly one source of truth.
	 * Returns null if the field is not fully catalogued here, even if
	 * EventCatalogLabels happens to carry a label for it — a field only
	 * appears in the visual builder when both its type metadata and its
	 * label are available.
	 *
	 * @param string $field_path The dot-notation field path.
	 *
	 * @return string|null
	 */
	public static function label( string $field_path ): ?string {
		if ( ! self::has( $field_path ) ) {
			return null;
		}

		return EventCatalogLabels::field_label( $field_path );
	}

	/**
	 * The permitted friendly `ConditionOperator` values for this field, or
	 * an empty array if uncatalogued.
	 *
	 * @param string $field_path The dot-notation field path.
	 *
	 * @return array<int, string>
	 */
	public static function operators( string $field_path ): array {
		return self::FIELDS[ $field_path ]['operators'] ?? array();
	}

	/**
	 * The fixed, non-sensitive preview value for this field, or null if
	 * uncatalogued.
	 *
	 * @param string $field_path The dot-notation field path.
	 *
	 * @return string|null
	 */
	public static function preview_value( string $field_path ): ?string {
		return self::FIELDS[ $field_path ]['preview_value'] ?? null;
	}

	/**
	 * The fixed value => label choice list for a `choice`-typed field, or
	 * an empty array for any other type or an uncatalogued field.
	 *
	 * @param string $field_path The dot-notation field path.
	 *
	 * @return array<string, string>
	 */
	public static function choice_options( string $field_path ): array {
		return self::FIELDS[ $field_path ]['choice_options'] ?? array();
	}

	/**
	 * Every catalogued field path, for coverage testing and for building
	 * the field-insert menu alongside an event's own allowed-field list.
	 *
	 * @return array<int, string>
	 */
	public static function all_field_paths(): array {
		return array_keys( self::FIELDS );
	}
}
