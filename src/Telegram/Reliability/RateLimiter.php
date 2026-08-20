<?php
/**
 * Per-scope token-bucket rate limiter.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Telegram\Reliability;

use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Persistence\SchemaHealth;

/**
 * A token bucket per (scope_type, scope_id), refilled by elapsed-time delta
 * at each check (docs/adr/0014). State persistence (read_state()/write_state())
 * is a separate, overridable seam from the pure refill/consume math in
 * try_consume(), following Dispatcher::schedule_action()'s exact precedent —
 * tests/unit/Telegram/Reliability/RateLimiterTest exercises the math
 * deterministically against an in-memory state double, with no WordPress
 * bootstrap or database involved; production code never overrides these
 * methods.
 */
class RateLimiter {

	/**
	 * Constructor.
	 *
	 * @param SchemaHealth         $schema_health Checked before every operation.
	 * @param callable(): int|null $clock         Returns the current time. Defaults to time().
	 */
	public function __construct(
		private readonly SchemaHealth $schema_health,
		?callable $clock = null
	) {
		$this->clock = $clock ?? static function (): int {
			return time();
		};
	}

	/**
	 * The injected clock.
	 *
	 * @var callable(): int
	 */
	private $clock;

	/**
	 * Attempts to consume one token from a scope's bucket, refilling it by
	 * elapsed time since its last check first. A scope with no prior state
	 * starts with a full bucket.
	 *
	 * @param string $scope_type          'bot' or 'destination'.
	 * @param int    $scope_id             The bot or destination's primary key.
	 * @param float  $capacity              The bucket's maximum token count.
	 * @param float  $refill_per_second     Tokens added per elapsed second.
	 *
	 * @return bool True if a token was consumed (the attempt is permitted).
	 */
	public function try_consume( string $scope_type, int $scope_id, float $capacity, float $refill_per_second ): bool {
		if ( ! $this->schema_health->is_available() ) {
			return true;
		}

		$now   = ( $this->clock )();
		$state = $this->read_state( $scope_type, $scope_id );

		if ( null === $state ) {
			$tokens_available = $capacity;
			$last_refill_at   = $now;
		} else {
			$elapsed          = max( 0, $now - $state['last_refill_at'] );
			$tokens_available = min( $capacity, $state['tokens_available'] + ( $elapsed * $refill_per_second ) );
			$last_refill_at   = $now;
		}

		if ( $tokens_available < 1.0 ) {
			$this->write_state( $scope_type, $scope_id, $tokens_available, $last_refill_at );
			return false;
		}

		$this->write_state( $scope_type, $scope_id, $tokens_available - 1.0, $last_refill_at );
		return true;
	}

	/**
	 * Reads a scope's current bucket state. Overridable by tests; see the
	 * class docblock.
	 *
	 * @param string $scope_type 'bot' or 'destination'.
	 * @param int    $scope_id   The bot or destination's primary key.
	 *
	 * @return array{tokens_available: float, last_refill_at: int}|null
	 */
	protected function read_state( string $scope_type, int $scope_id ): ?array {
		global $wpdb;

		$table = $wpdb->prefix . Migrator::RATE_LIMIT_TABLE;
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE scope_type = %s AND scope_id = %d", $scope_type, $scope_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		if ( null === $row ) {
			return null;
		}

		$last_refill_at = strtotime( $row['last_refill_at'] . ' UTC' );

		return array(
			'tokens_available' => (float) $row['tokens_available'],
			'last_refill_at'   => false === $last_refill_at ? 0 : $last_refill_at,
		);
	}

	/**
	 * Writes (inserts or replaces) a scope's bucket state. Overridable by
	 * tests; see the class docblock.
	 *
	 * @param string $scope_type       'bot' or 'destination'.
	 * @param int    $scope_id          The bot or destination's primary key.
	 * @param float  $tokens_available  The new token count.
	 * @param int    $last_refill_at     The timestamp this state was computed at.
	 */
	protected function write_state( string $scope_type, int $scope_id, float $tokens_available, int $last_refill_at ): void {
		global $wpdb;

		$table = $wpdb->prefix . Migrator::RATE_LIMIT_TABLE;

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (scope_type, scope_id, tokens_available, last_refill_at) " // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				. 'VALUES (%s, %d, %f, %s) '
				. 'ON DUPLICATE KEY UPDATE tokens_available = VALUES(tokens_available), last_refill_at = VALUES(last_refill_at)',
				$scope_type,
				$scope_id,
				$tokens_available,
				gmdate( 'Y-m-d H:i:s', $last_refill_at )
			)
		);
	}
}
