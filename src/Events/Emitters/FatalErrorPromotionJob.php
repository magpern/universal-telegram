<?php
/**
 * Safe, idempotent promotion of fatal-error markers.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Events\Emitters;

use UniversalTelegram\Events\Registry;
use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Privacy\Classification;

/**
 * Phase 2 of the two-phase fatal-error mechanism (M02 plan §8.6): a
 * recurring Action Scheduler action running in the normal, safe queued-job
 * execution context. Selects pending markers and emits
 * WordPress.fatal_error for each, keyed by the marker's own stable
 * identity — idempotent by construction via Events\EventIdentity, not a
 * lock, so an interrupted-and-rerun job never produces a second
 * notification.
 */
final class FatalErrorPromotionJob {

	public const HOOK = 'universal_telegram_promote_fatal_error_markers';

	public const FATAL_ERROR = 'wordpress.fatal_error';

	/**
	 * Constructor.
	 *
	 * @param SchemaHealth $schema_health Checked before every run.
	 */
	public function __construct( private readonly SchemaHealth $schema_health ) {}

	/**
	 * Registers this job's event type.
	 *
	 * @param Registry $registry The current request's event registry.
	 */
	public function register_event_types( Registry $registry ): void {
		$fields = array(
			'payload.error_type'    => Classification::PUBLIC,
			'payload.location_hash' => Classification::PUBLIC,
		);

		$registry->register( self::FATAL_ERROR, 1, $fields, array_keys( $fields ), array_keys( $fields ) );
	}

	/**
	 * Runs one promotion pass.
	 */
	public function run(): void {
		if ( ! $this->schema_health->is_available() ) {
			return;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::FATAL_ERROR_MARKERS_TABLE;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- fixed table name, never user input.
		$rows = $wpdb->get_results( "SELECT id, error_type, location_hash, occurred_at FROM {$table} WHERE status = 'pending'", ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return;
		}

		foreach ( $rows as $row ) {
			$marker_id       = (int) $row['id'];
			$idempotency_key = sprintf( 'fatal_marker:%d:%s', $marker_id, (string) $row['occurred_at'] );

			universal_telegram_emit_event(
				self::FATAL_ERROR,
				array(
					'payload' => array(
						'error_type'    => (string) $row['error_type'],
						'location_hash' => (string) $row['location_hash'],
					),
				),
				$idempotency_key
			);

			$wpdb->update(
				$table,
				array(
					'status'      => 'promoted',
					'promoted_at' => current_time( 'mysql', true ),
				),
				array( 'id' => $marker_id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
		}
	}
}
