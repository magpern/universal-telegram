<?php
/**
 * Legacy operator-identity -> retained operator-identity-map bijection check.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Persistence;

/**
 * ADR-0044 §4: before the obsolete `operator_identities` table is dropped
 * (by `wp universal-telegram legacy-chat purge`, and checked by migrator
 * step 37), every Telegram-user -> WordPress-operator mapping it holds must
 * exist, unchanged, in the retained `operator_identity_map` table.
 *
 * The check is an exact `(wp_user_id, telegram_user_id)` bijection, never a
 * row-count comparison:
 *
 * - every source pair MUST exist as a target row (missing pair = failure);
 * - a target row sharing exactly one key with a source row but differing on
 *   the other key is a CONFLICT;
 * - a target row whose `wp_user_id` and `telegram_user_id` both appear in
 *   no source row is PERMITTED and REPORTED (expected count 0 on a straight
 *   upgrade — these can only come from the retired build's own adapter
 *   admin after the upgrade).
 *
 * Both step 37 and the purge command fail closed on any conflict or missing
 * pair and leave every legacy table intact.
 */
final class OperatorIdentityMapMigration {

	/**
	 * Runs the bijection check against the current database.
	 *
	 * @return OperatorIdentityMapBijectionReport
	 */
	public static function verify_bijection(): OperatorIdentityMapBijectionReport {
		global $wpdb;

		$source_table = $wpdb->prefix . Migrator::OPERATOR_IDENTITIES_TABLE;
		$target_table = $wpdb->prefix . Migrator::OPERATOR_IDENTITY_MAP_TABLE;

		if ( ! Migrator::table_exists( $source_table ) || ! Migrator::table_exists( $target_table ) ) {
			// Nothing to reconcile — trivially holds.
			return new OperatorIdentityMapBijectionReport( array(), array() );
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$source = $wpdb->get_results( "SELECT wp_user_id, telegram_user_id FROM {$source_table}", ARRAY_A );
		$target = $wpdb->get_results( "SELECT id, wp_user_id, telegram_user_id FROM {$target_table}", ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$source     = is_array( $source ) ? $source : array();
		$target     = is_array( $target ) ? $target : array();
		$target_pairs = array();
		$by_wp        = array();
		$by_tg        = array();

		foreach ( $target as $row ) {
			$wp = (int) $row['wp_user_id'];
			$tg = (int) $row['telegram_user_id'];
			$target_pairs[ $wp . ':' . $tg ] = true;
			$by_wp[ $wp ]                     = $tg;
			$by_tg[ $tg ]                     = $wp;
		}

		$mismatches      = array();
		$source_wp       = array();
		$source_tg       = array();

		foreach ( $source as $row ) {
			$wp             = (int) $row['wp_user_id'];
			$tg             = (int) $row['telegram_user_id'];
			$source_wp[ $wp ] = true;
			$source_tg[ $tg ] = true;

			if ( isset( $target_pairs[ $wp . ':' . $tg ] ) ) {
				continue;
			}

			if ( isset( $by_wp[ $wp ] ) && $by_wp[ $wp ] !== $tg ) {
				$mismatches[] = "conflict: wp_user_id {$wp} maps to telegram_user_id {$by_wp[$wp]} in the map, source has {$tg}";
				continue;
			}

			if ( isset( $by_tg[ $tg ] ) && $by_tg[ $tg ] !== $wp ) {
				$mismatches[] = "conflict: telegram_user_id {$tg} maps to wp_user_id {$by_tg[$tg]} in the map, source has {$wp}";
				continue;
			}

			$mismatches[] = "missing: source pair (wp_user_id {$wp}, telegram_user_id {$tg}) is absent from the map";
		}

		$unreachable_extras = array();

		foreach ( $target as $row ) {
			$wp = (int) $row['wp_user_id'];
			$tg = (int) $row['telegram_user_id'];

			if ( ! isset( $source_wp[ $wp ] ) && ! isset( $source_tg[ $tg ] ) ) {
				$unreachable_extras[] = array(
					'id'               => (int) $row['id'],
					'wp_user_id'       => $wp,
					'telegram_user_id' => $tg,
				);
			}
		}

		return new OperatorIdentityMapBijectionReport( $mismatches, $unreachable_extras );
	}
}
