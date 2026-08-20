<?php
/**
 * Durable, PUBLIC-only event history projection.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Events;

use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Privacy\Redactor;

/**
 * Writes only the fields listed in Registry::history_projection_fields_for()
 * — by registration-time construction (docs/adr/0017), every one of those
 * fields is already classified PUBLIC. Privacy\Redactor::redact() is still
 * applied against the event type's full classification map as defense in
 * depth (M02 plan §5.4), so a future change to Redactor's own logic, or to
 * the registration-time check, cannot silently regress this guarantee
 * without a second, independent test catching it. Insertion is an
 * idempotent INSERT IGNORE keyed on the event_id unique constraint — a
 * replayed event produces no second history row.
 */
final class EventHistoryRepository {

	/**
	 * Constructor.
	 *
	 * @param SchemaHealth $schema_health Checked before every write.
	 * @param Registry     $registry      Supplies each event type's history-projection fields and classification map.
	 * @param Redactor     $redactor      Applied to the candidate projection as defense in depth.
	 */
	public function __construct(
		private readonly SchemaHealth $schema_health,
		private readonly Registry $registry,
		private readonly Redactor $redactor
	) {}

	/**
	 * Records one event occurrence's PUBLIC-only projection. A no-op,
	 * logged not thrown, if the schema is unavailable (docs/adr/0007).
	 *
	 * @param EventEnvelope $event The event to project.
	 */
	public function record( EventEnvelope $event ): void {
		if ( ! $this->schema_health->is_available() ) {
			return;
		}

		$event_type          = $event->event_type();
		$projection_fields   = $this->registry->history_projection_fields_for( $event_type );
		$classification_map  = $this->registry->classification_map_for( $event_type );

		$candidate = array();
		foreach ( $projection_fields as $path ) {
			$value = $event->value_at( $path );

			if ( null !== $value ) {
				$this->set_path( $candidate, $path, $value );
			}
		}

		$projected = $this->redactor->redact( $candidate, $classification_map );

		global $wpdb;

		$table = $wpdb->prefix . Migrator::EVENT_HISTORY_TABLE;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name, never user input.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (event_id, event_type, schema_version, occurred_at, source, projected_fields_json, created_at) VALUES (%s, %s, %d, %s, %s, %s, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$event->event_id(),
				$event_type,
				$event->schema_version(),
				$event->occurred_at()->format( 'Y-m-d H:i:s' ),
				$event->source()->value,
				wp_json_encode( $projected ),
				current_time( 'mysql', true )
			)
		);
	}

	/**
	 * Counts event_history rows recorded within the last 24 hours. Used
	 * only for diagnostics aggregation.
	 *
	 * @return int
	 */
	public function count_24h(): int {
		if ( ! $this->schema_health->is_available() ) {
			return 0;
		}

		global $wpdb;

		$table     = $wpdb->prefix . Migrator::EVENT_HISTORY_TABLE;
		$threshold = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE occurred_at >= %s", $threshold ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * Sets a value at a dot-notation path inside a nested array, creating
	 * intermediate arrays as needed.
	 *
	 * @param array<string, mixed> $data  The array to modify, by reference.
	 * @param string                $path  The dot-notation path.
	 * @param mixed                 $value The value to set.
	 */
	private function set_path( array &$data, string $path, $value ): void {
		$segments = explode( '.', $path );
		$last     = array_key_last( $segments );
		$cursor   = &$data;

		foreach ( $segments as $index => $segment ) {
			if ( $index === $last ) {
				$cursor[ $segment ] = $value;
				break;
			}

			if ( ! isset( $cursor[ $segment ] ) || ! is_array( $cursor[ $segment ] ) ) {
				$cursor[ $segment ] = array();
			}

			$cursor = &$cursor[ $segment ];
		}
	}
}
