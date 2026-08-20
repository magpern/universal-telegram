<?php
/**
 * Atomic migration lock.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Persistence;

/**
 * Coordinates concurrent migration attempts using a genuinely atomic single
 * SQL statement for every state transition — never a separate read
 * followed by a separate write. See docs/adr/0007 for the full contract
 * and the interleaving this design defends against.
 */
final class MigrationLock {

	private const OPTION_NAME   = 'universal_telegram_migration_lock';
	private const STALE_SECONDS = 300;

	/**
	 * Attempts to acquire the lock.
	 *
	 * @return MigrationLockHandle|null Null if another process currently
	 *                                   holds a non-stale lock.
	 */
	public function acquire(): ?MigrationLockHandle {
		global $wpdb;

		$token = wp_generate_uuid4();
		$now   = time();
		$value = $token . '|' . $now;

		$suppress = $wpdb->suppress_errors( true );
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				self::OPTION_NAME,
				$value
			)
		);
		$wpdb->suppress_errors( $suppress );

		if ( false !== $inserted && $inserted > 0 ) {
			wp_cache_delete( self::OPTION_NAME, 'options' );
			return new MigrationLockHandle( $token, $value );
		}

		return $this->try_reclaim_stale_lock( $token, $value, $now );
	}

	/**
	 * Releases a held lock. A safe no-op if the lock no longer holds the
	 * exact value the caller's handle recorded, because some other process
	 * has since reclaimed it as stale.
	 *
	 * @param MigrationLockHandle $handle The handle returned by acquire().
	 */
	public function release( MigrationLockHandle $handle ): void {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				self::OPTION_NAME,
				$handle->value()
			)
		);

		wp_cache_delete( self::OPTION_NAME, 'options' );
	}

	/**
	 * Attempts a single atomic compare-and-swap reclamation of a stale lock.
	 *
	 * @param string $token The fresh token to write if reclamation succeeds.
	 * @param string $value The fresh value to write if reclamation succeeds.
	 * @param int    $now   The current time, used to judge staleness.
	 *
	 * @return MigrationLockHandle|null
	 */
	private function try_reclaim_stale_lock( string $token, string $value, int $now ): ?MigrationLockHandle {
		global $wpdb;

		$current = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
				self::OPTION_NAME
			)
		);

		if ( null === $current ) {
			return null;
		}

		$parts        = explode( '|', $current, 2 );
		$current_time = isset( $parts[1] ) ? (int) $parts[1] : 0;

		if ( ( $now - $current_time ) < self::STALE_SECONDS ) {
			return null;
		}

		$swapped = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$value,
				self::OPTION_NAME,
				$current
			)
		);

		if ( 1 !== $swapped ) {
			return null;
		}

		wp_cache_delete( self::OPTION_NAME, 'options' );

		return new MigrationLockHandle( $token, $value );
	}
}
