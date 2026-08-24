<?php
/**
 * Threshold-alert cooldown/checkpoint persistence.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Automations\Intelligence;

use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Persistence\SchemaHealth;

/**
 * Owns universal_telegram_operational_alert_state (M11B plan §2.2/§4, step
 * 27) — three fixed, migration-seeded rows, one per IntelligenceSettings::
 * ALERT_TYPES value. The structural anti-flood guarantee: try_fire() is an
 * atomic compare-and-set that only succeeds once per hour per alert type,
 * regardless of how many times the evaluated condition remains true.
 */
final class AlertRepository {

	private const COOLDOWN_SECONDS = HOUR_IN_SECONDS;

	/**
	 * Constructor.
	 *
	 * @param SchemaHealth $schema_health Checked before every operation.
	 */
	public function __construct( private readonly SchemaHealth $schema_health ) {}

	/**
	 * The given alert type's own last-fired timestamp, or null if it has
	 * never fired.
	 *
	 * @param string $alert_type One of IntelligenceSettings::ALERT_TYPES.
	 *
	 * @return string|null
	 */
	public function last_fired_at( string $alert_type ): ?string {
		if ( ! $this->schema_health->is_available() ) {
			return null;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::OPERATIONAL_ALERT_STATE_TABLE;

		$value = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT last_fired_at FROM {$table} WHERE alert_type = %s",
				$alert_type
			)
		);

		return null === $value ? null : (string) $value;
	}

	/**
	 * Unconditionally records that this alert type was evaluated on this
	 * tick — diagnostics only, never gates firing.
	 *
	 * @param string $alert_type One of IntelligenceSettings::ALERT_TYPES.
	 * @param string $now        The evaluation timestamp.
	 */
	public function record_evaluated( string $alert_type, string $now ): void {
		if ( ! $this->schema_health->is_available() ) {
			return;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::OPERATIONAL_ALERT_STATE_TABLE;

		$wpdb->update( $table, array( 'last_evaluated_at' => $now ), array( 'alert_type' => $alert_type ) );
	}

	/**
	 * Attempts to atomically claim a fire for the given alert type: succeeds
	 * only if it has never fired, or its last fire was more than one hour
	 * ago — the fixed, structural 1-hour re-fire cooldown (§2.2), enforced
	 * as a single conditional UPDATE, independent of continued condition
	 * persistence.
	 *
	 * @param string $alert_type One of IntelligenceSettings::ALERT_TYPES.
	 * @param string $now        The candidate fire timestamp.
	 *
	 * @return bool Whether this call won the fire.
	 */
	public function try_fire( string $alert_type, string $now ): bool {
		if ( ! $this->schema_health->is_available() ) {
			return false;
		}

		global $wpdb;

		$table     = $wpdb->prefix . Migrator::OPERATIONAL_ALERT_STATE_TABLE;
		$threshold = gmdate( 'Y-m-d H:i:s', strtotime( $now . ' UTC' ) - self::COOLDOWN_SECONDS );

		$updated = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE {$table} SET last_fired_at = %s, last_evaluated_at = %s
					WHERE alert_type = %s AND (last_fired_at IS NULL OR last_fired_at < %s)",
				$now,
				$now,
				$alert_type,
				$threshold
			)
		);

		return is_int( $updated ) && $updated > 0;
	}
}
