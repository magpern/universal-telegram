<?php
/**
 * The guarded legacy-chat data removal service (ADR-0044 §5).
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Persistence;

/**
 * Drops only the obsolete legacy-chat / migration-cutover tables and
 * options named in {@see Migrator::LEGACY_TABLES} / {@see Migrator::LEGACY_OPTIONS},
 * always preserving every transport/adapter table (and the encrypted bot
 * credentials in particular). Before dropping the obsolete
 * `operator_identities` table it re-runs the exact
 * `(wp_user_id, telegram_user_id)` bijection against `operator_identity_map`
 * and aborts — removing nothing — on any conflict or missing pair.
 */
final class LegacyChatPurge {

	private const DB_VERSION_OPTION = 'universal_telegram_db_version';

	/**
	 * Lists what a real run would remove; touches nothing.
	 *
	 * @return LegacyChatPurgeReport
	 */
	public function dry_run(): LegacyChatPurgeReport {
		global $wpdb;

		$lines   = array();
		$present = array();

		foreach ( Migrator::LEGACY_TABLES as $table ) {
			$qualified = $wpdb->prefix . $table;

			if ( Migrator::table_exists( $qualified ) ) {
				$present[] = $qualified;
				$lines[]   = 'would drop table: ' . $qualified;
			}
		}

		foreach ( Migrator::LEGACY_OPTIONS as $option ) {
			if ( null !== get_option( $option, null ) ) {
				$lines[] = 'would delete option: ' . $option;
			}
		}

		$bijection = OperatorIdentityMapMigration::verify_bijection();

		foreach ( $bijection->unreachable_extras() as $extra ) {
			$lines[] = sprintf(
				'note: operator_identity_map row #%d (wp_user_id %d, telegram_user_id %d) is unreachable through any operator_identities key — kept',
				$extra['id'],
				$extra['wp_user_id'],
				$extra['telegram_user_id']
			);
		}

		if ( ! $bijection->holds() ) {
			foreach ( $bijection->mismatches() as $mismatch ) {
				$lines[] = 'BLOCKER: ' . $mismatch;
			}

			return new LegacyChatPurgeReport( false, $lines, 'operator-identity bijection does not hold — a real purge would abort without removing anything' );
		}

		return new LegacyChatPurgeReport(
			true,
			$lines,
			sprintf( 'dry run: %d legacy tables present, bijection holds', count( $present ) )
		);
	}

	/**
	 * Removes the legacy manifest. Aborts (removing nothing) if the
	 * operator-identity bijection does not hold.
	 *
	 * @return LegacyChatPurgeReport
	 */
	public function run(): LegacyChatPurgeReport {
		global $wpdb;

		$bijection = OperatorIdentityMapMigration::verify_bijection();

		if ( ! $bijection->holds() ) {
			$lines = array( 'ABORTED — no table or option was removed.' );

			foreach ( $bijection->mismatches() as $mismatch ) {
				$lines[] = 'BLOCKER: ' . $mismatch;
			}

			return new LegacyChatPurgeReport( false, $lines, 'operator-identity bijection does not hold — nothing removed' );
		}

		$lines   = array();
		$dropped = 0;

		foreach ( Migrator::LEGACY_TABLES as $table ) {
			$qualified = $wpdb->prefix . $table;

			if ( ! Migrator::table_exists( $qualified ) ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( "DROP TABLE IF EXISTS {$qualified}" );
			++$dropped;
			$lines[] = 'dropped table: ' . $qualified;
		}

		foreach ( Migrator::LEGACY_OPTIONS as $option ) {
			if ( null !== get_option( $option, null ) ) {
				delete_option( $option );
				$lines[] = 'deleted option: ' . $option;
			}
		}

		delete_option( Migrator::LEGACY_CHAT_RETIRED_OPTION );
		update_option( self::DB_VERSION_OPTION, 37 );
		$lines[] = 'cleared retirement marker; db_version set to 37';

		// Postcondition.
		$violations = array();

		foreach ( Migrator::LEGACY_TABLES as $table ) {
			if ( Migrator::table_exists( $wpdb->prefix . $table ) ) {
				$violations[] = 'legacy table still present: ' . $wpdb->prefix . $table;
			}
		}

		foreach ( array( Migrator::BOTS_TABLE, Migrator::OPERATOR_IDENTITY_MAP_TABLE, Migrator::SUPPORT_CHAT_BINDINGS_TABLE, Migrator::OPERATIONAL_ALERT_STATE_TABLE ) as $must_keep ) {
			if ( ! Migrator::table_exists( $wpdb->prefix . $must_keep ) ) {
				$violations[] = 'preserved table missing: ' . $wpdb->prefix . $must_keep;
			}
		}

		if ( array() !== $violations ) {
			return new LegacyChatPurgeReport( false, array_merge( $lines, $violations ), 'purge postcondition failed' );
		}

		return new LegacyChatPurgeReport( true, $lines, sprintf( 'purged %d legacy tables; transport/adapter data preserved', $dropped ) );
	}
}
