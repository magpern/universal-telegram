<?php
/**
 * Bounded event_history counters for the generic operational alerts.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Automations;

use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Persistence\SchemaHealth;

/**
 * Bounded COUNT queries against `event_history`, used by
 * {@see Intelligence\AlertEvaluator} for the three fixed threshold alerts
 * (checkout-failure, order-failure spike, JS-error spike). Extracted from
 * the removed operational-summary repository (ADR-0044 §1) so the alert
 * engine carries no dependency on the retired summary subsystem.
 */
final class EventCountAggregator {

	/**
	 * Constructor.
	 *
	 * @param SchemaHealth $schema_health Per-request schema availability.
	 */
	public function __construct( private readonly SchemaHealth $schema_health ) {}

	/**
	 * A bounded COUNT against event_history for one event type since a
	 * given timestamp.
	 *
	 * @param string $event_type The event type.
	 * @param string $since      The inclusive lower bound, 'Y-m-d H:i:s' UTC.
	 *
	 * @return int
	 */
	public function count_event_type_since( string $event_type, string $since ): int {
		if ( ! $this->schema_health->is_available() ) {
			return 0;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::EVENT_HISTORY_TABLE;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM {$table} WHERE event_type = %s AND occurred_at >= %s",
				$event_type,
				$since
			)
		);
	}

	/**
	 * A bounded count of `visitor.javascript_error` rows since a given
	 * timestamp whose payload.error_category matches. (After ADR-0044 the
	 * browser tracker no longer emits these, so this is normally 0 — the
	 * JS-error-spike alert config is retained but inert unless another
	 * source records that event type.)
	 *
	 * @param string $category One of runtime|promise_rejection|resource_load.
	 * @param string $since    The inclusive lower bound, 'Y-m-d H:i:s' UTC.
	 *
	 * @return int
	 */
	public function count_error_category_since( string $category, string $since ): int {
		if ( ! $this->schema_health->is_available() ) {
			return 0;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::EVENT_HISTORY_TABLE;

		$rows = $wpdb->get_col(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT projected_fields_json FROM {$table} WHERE event_type = %s AND occurred_at >= %s",
				'visitor.javascript_error',
				$since
			)
		);

		$count = 0;

		foreach ( $rows as $json ) {
			$decoded = json_decode( (string) $json, true );

			if ( is_array( $decoded ) && ( $decoded['payload']['error_category'] ?? null ) === $category ) {
				++$count;
			}
		}

		return $count;
	}
}
