<?php
/**
 * Shutdown-safe fatal-error marker capture.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Events\Emitters;

use Throwable;
use UniversalTelegram\Persistence\Migrator;

/**
 * Phase 1 of the two-phase fatal-error mechanism (M02 plan §8.6): a
 * register_shutdown_function() callback that, on detecting a fatal-class
 * error, performs a single, defensively-guarded raw upsert against
 * fatal_error_markers. Never throws, never performs an external call,
 * never touches the plugin's own full object graph (unreliable at
 * shutdown after a fatal error). Never stores the error message, a stack
 * trace, or the raw file path — only a fixed error-type constant and a
 * SHA-256 hash of "file:line".
 */
final class FatalErrorMarkerWriter {

	private const FATAL_TYPES = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR );

	/**
	 * Registers the shutdown callback.
	 */
	public function register(): void {
		register_shutdown_function( array( $this, 'handle_shutdown' ) );
	}

	/**
	 * The shutdown callback.
	 */
	public function handle_shutdown(): void {
		$this->write_marker_for( error_get_last() );
	}

	/**
	 * The testable core of the shutdown callback. Swallows every failure
	 * mode — a shutdown handler must never itself fatal.
	 *
	 * @param array{type: int, message: string, file: string, line: int}|null $error error_get_last()'s return value.
	 */
	public function write_marker_for( ?array $error ): void {
		try {
			if ( null === $error || ! in_array( $error['type'], self::FATAL_TYPES, true ) ) {
				return;
			}

			global $wpdb;

			if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
				return;
			}

			$error_type    = $this->error_type_name( (int) $error['type'] );
			$location_hash = hash( 'sha256', $error['file'] . ':' . $error['line'] );
			$table         = $wpdb->prefix . Migrator::FATAL_ERROR_MARKERS_TABLE;
			$now           = gmdate( 'Y-m-d H:i:s' );

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name, never user input.
			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$table} (error_type, location_hash, status, occurred_at, created_at) VALUES (%s, %s, 'pending', %s, %s) " . // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"ON DUPLICATE KEY UPDATE occurred_at = VALUES(occurred_at), status = IF(status = 'promoted' AND promoted_at < DATE_SUB(NOW(), INTERVAL 1 HOUR), 'pending', status)",
					$error_type,
					$location_hash,
					$now,
					$now
				)
			);
		} catch ( Throwable $exception ) {
			// A shutdown handler must never itself fatal; every failure
			// mode here is silently swallowed by design.
			unset( $exception );
		}
	}

	/**
	 * Maps a PHP fatal-class error constant to its own fixed name.
	 *
	 * @param int $type The PHP error constant.
	 *
	 * @return string
	 */
	private function error_type_name( int $type ): string {
		return match ( $type ) {
			E_ERROR => 'E_ERROR',
			E_PARSE => 'E_PARSE',
			E_CORE_ERROR => 'E_CORE_ERROR',
			E_COMPILE_ERROR => 'E_COMPILE_ERROR',
			default => 'E_UNKNOWN',
		};
	}
}
