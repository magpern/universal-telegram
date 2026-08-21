<?php
/**
 * Strict, allow-listed validation of one visitor-ingest request body.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Events\Visitor;

/**
 * Pure, WordPress-free validator for the short-code batch schema
 * (M04 plan §4.4): `{"v":1,"visit":"<32 lower-hex>","events":[{"uuid":...,
 * "type":...,"data":{...}}, ...]}`. Rejects (returns null) on any unknown
 * top-level key, unknown event type, malformed visit/uuid shape, oversized
 * batch, or any field not present in the fixed per-type field spec below —
 * fail-closed by construction, never partially accepts a malformed batch.
 * WooCommerce-gated types are validated by shape here regardless of
 * WooCommerce presence; IngestController alone decides whether a given
 * type is actually registered and therefore accepted.
 */
final class IngestRequestValidator {

	public const MAX_BODY_BYTES = 8192;
	private const MAX_EVENTS      = 10;
	private const MAX_DATA_KEYS   = 6;
	private const MAX_SCALAR_BYTES = 190;

	private const UUID4_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';
	private const VISIT_PATTERN = '/^[0-9a-f]{32}$/';

	private const SHORT_CODES = array(
		'ss' => VisitorEventCatalog::SESSION_STARTED,
		'pv' => VisitorEventCatalog::PAGE_VIEWED,
		'nv' => VisitorEventCatalog::NAVIGATION,
		'sr' => VisitorEventCatalog::SEARCH_PERFORMED,
		'ck' => VisitorEventCatalog::CLICK,
		'je' => VisitorEventCatalog::JAVASCRIPT_ERROR,
		'pd' => 'visitor.product_viewed',
		'ac' => 'visitor.add_to_cart_intent',
		'cs' => 'visitor.checkout_started_intent',
	);

	/**
	 * Dot-free field name to a validation rule name; the field's classified
	 * dot-path (subject.*/payload.*) is assigned by IngestController, not
	 * here — this class only proves the raw client value is well-formed.
	 *
	 * @var array<string, array<string, string>>
	 */
	private const FIELD_SPECS = array(
		'visitor.session_started'         => array(),
		'visitor.page_viewed'             => array(
			'path'      => 'path',
			'page_type' => 'page_type_enum',
		),
		'visitor.navigation'              => array(
			'from_path' => 'path',
			'to_path'   => 'path',
		),
		'visitor.search_performed'        => array( 'result_count' => 'nonneg_int' ),
		'visitor.click'                   => array( 'target_key' => 'target_key' ),
		'visitor.javascript_error'        => array( 'error_category' => 'error_category_enum' ),
		'visitor.product_viewed'          => array( 'product_id' => 'positive_int' ),
		'visitor.add_to_cart_intent'      => array( 'product_id' => 'positive_int' ),
		'visitor.checkout_started_intent' => array(),
	);

	/**
	 * Validates a decoded request body.
	 *
	 * @param mixed $decoded The result of json_decode( $raw_body, true ).
	 *
	 * @return array{visit: string, events: array<int, array{uuid: string, event_type: string, fields: array<string, mixed>}>}|null
	 */
	public function validate( $decoded ): ?array {
		if ( ! is_array( $decoded ) ) {
			return null;
		}

		foreach ( array_keys( $decoded ) as $key ) {
			if ( ! in_array( $key, array( 'v', 'visit', 'events' ), true ) ) {
				return null;
			}
		}

		if ( ! isset( $decoded['v'] ) || 1 !== $decoded['v'] ) {
			return null;
		}

		if ( ! isset( $decoded['visit'] ) || ! is_string( $decoded['visit'] ) || 1 !== preg_match( self::VISIT_PATTERN, $decoded['visit'] ) ) {
			return null;
		}

		if ( ! isset( $decoded['events'] ) || ! is_array( $decoded['events'] ) || ! array_is_list( $decoded['events'] ) ) {
			return null;
		}

		$count = count( $decoded['events'] );

		if ( $count < 1 || $count > self::MAX_EVENTS ) {
			return null;
		}

		$events = array();

		foreach ( $decoded['events'] as $raw_event ) {
			$validated = $this->validate_event( $raw_event );

			if ( null === $validated ) {
				return null;
			}

			$events[] = $validated;
		}

		return array(
			'visit'  => $decoded['visit'],
			'events' => $events,
		);
	}

	/**
	 * @param mixed $raw_event One element of the "events" array.
	 *
	 * @return array{uuid: string, event_type: string, fields: array<string, mixed>}|null
	 */
	private function validate_event( $raw_event ): ?array {
		if ( ! is_array( $raw_event ) ) {
			return null;
		}

		foreach ( array_keys( $raw_event ) as $key ) {
			if ( ! in_array( $key, array( 'uuid', 'type', 'data' ), true ) ) {
				return null;
			}
		}

		if ( ! isset( $raw_event['uuid'] ) || ! is_string( $raw_event['uuid'] ) || 1 !== preg_match( self::UUID4_PATTERN, $raw_event['uuid'] ) ) {
			return null;
		}

		if ( ! isset( $raw_event['type'] ) || ! is_string( $raw_event['type'] ) || ! isset( self::SHORT_CODES[ $raw_event['type'] ] ) ) {
			return null;
		}

		$event_type = self::SHORT_CODES[ $raw_event['type'] ];
		$data       = $raw_event['data'] ?? array();

		if ( ! is_array( $data ) || count( $data ) > self::MAX_DATA_KEYS ) {
			return null;
		}

		$spec = self::FIELD_SPECS[ $event_type ];

		foreach ( array_keys( $data ) as $key ) {
			if ( ! isset( $spec[ $key ] ) ) {
				return null;
			}
		}

		$fields = array();

		foreach ( $spec as $field_name => $rule ) {
			if ( ! array_key_exists( $field_name, $data ) ) {
				return null;
			}

			$validated_value = $this->validate_field( $rule, $data[ $field_name ] );

			if ( null === $validated_value ) {
				return null;
			}

			$fields[ $field_name ] = $validated_value;
		}

		return array(
			'uuid'       => $raw_event['uuid'],
			'event_type' => $event_type,
			'fields'     => $fields,
		);
	}

	/**
	 * @param string $rule  One of the fixed rule names in FIELD_SPECS.
	 * @param mixed  $value The raw client-supplied value.
	 *
	 * @return mixed|null The normalized value, or null if invalid.
	 */
	private function validate_field( string $rule, $value ) {
		switch ( $rule ) {
			case 'path':
				if ( ! is_string( $value ) || '' === $value ) {
					return null;
				}
				if ( strlen( $value ) > self::MAX_SCALAR_BYTES ) {
					return null;
				}
				if ( '/' !== $value[0] || false !== strpos( $value, '?' ) || false !== strpos( $value, '#' ) ) {
					return null;
				}
				return $value;

			case 'page_type_enum':
				return in_array( $value, array( 'home', 'singular', 'search', 'archive', 'other' ), true ) ? $value : null;

			case 'error_category_enum':
				return in_array( $value, array( 'runtime', 'promise_rejection', 'resource_load' ), true ) ? $value : null;

			case 'nonneg_int':
				return ( is_int( $value ) && $value >= 0 && $value <= 1000000 ) ? $value : null;

			case 'positive_int':
				return ( is_int( $value ) && $value > 0 ) ? $value : null;

			case 'target_key':
				if ( ! is_string( $value ) || '' === $value || strlen( $value ) > self::MAX_SCALAR_BYTES ) {
					return null;
				}
				return $value;

			default:
				return null;
		}
	}
}
