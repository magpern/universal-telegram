<?php
/**
 * Per-scope circuit breaker.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Telegram\Reliability;

use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Queue\RetryPolicy;

/**
 * Two independent scopes, 'bot' and 'destination', each with its own
 * closed/open/half_open state row in universal_telegram_circuit_breaker_state
 * (docs/adr/0014). Wired into the send path by Telegram\Outbound\SendMessageHandler
 * (WP8). Cooldown escalation on a repeated failed half-open probe reuses
 * Queue\RetryPolicy::delay_seconds(), keyed by how many consecutive
 * failures have occurred past the opening threshold, rather than a
 * duplicated backoff implementation.
 */
class CircuitBreaker {

	private const FIRST_COOLDOWN_SECONDS = 60;

	/**
	 * Constructor.
	 *
	 * @param SchemaHealth         $schema_health Checked before every operation.
	 * @param RetryPolicy          $retry_policy   Reused for escalating cooldown math.
	 * @param callable(): int|null $clock  Returns the current time. Defaults to time().
	 */
	public function __construct(
		private readonly SchemaHealth $schema_health,
		private readonly RetryPolicy $retry_policy,
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
	 * Whether an attempt is currently permitted for this scope. A closed
	 * breaker always permits; an open breaker permits only once its
	 * next_probe_at has passed, transitioning it to half_open and granting
	 * exactly that one trial; a half_open breaker (already granted its one
	 * trial) permits no further attempt until resolved.
	 *
	 * @param string $scope_type 'bot' or 'destination'.
	 * @param int    $scope_id   The bot or destination's primary key.
	 *
	 * @return bool
	 */
	public function may_attempt( string $scope_type, int $scope_id ): bool {
		if ( ! $this->schema_health->is_available() ) {
			return true;
		}

		$row = $this->read( $scope_type, $scope_id );

		if ( null === $row || CircuitBreakerState::CLOSED->value === $row['state'] ) {
			return true;
		}

		if ( CircuitBreakerState::HALF_OPEN->value === $row['state'] ) {
			return false;
		}

		// Open.
		$next_probe_at = null === $row['next_probe_at'] ? null : strtotime( $row['next_probe_at'] . ' UTC' );

		if ( null === $next_probe_at || $this->now() < $next_probe_at ) {
			return false;
		}

		$this->write( $scope_type, $scope_id, CircuitBreakerState::HALF_OPEN, (int) $row['consecutive_failures'], $row['opened_at'], null );

		return true;
	}

	/**
	 * Throws if may_attempt() would return false; otherwise a no-op.
	 *
	 * @param string $scope_type 'bot' or 'destination'.
	 * @param int    $scope_id   The bot or destination's primary key.
	 *
	 * @throws CircuitOpenException If an attempt is not currently permitted.
	 */
	public function assert_may_attempt( string $scope_type, int $scope_id ): void {
		if ( $this->may_attempt( $scope_type, $scope_id ) ) {
			return;
		}

		$row = $this->read( $scope_type, $scope_id );

		throw new CircuitOpenException(
			null !== $row && null !== $row['next_probe_at'] ? strtotime( $row['next_probe_at'] . ' UTC' ) : null
		);
	}

	/**
	 * The scope's current state, defaulting to CLOSED for a scope with no row yet.
	 *
	 * @param string $scope_type 'bot' or 'destination'.
	 * @param int    $scope_id   The bot or destination's primary key.
	 *
	 * @return CircuitBreakerState
	 */
	public function state( string $scope_type, int $scope_id ): CircuitBreakerState {
		$row = $this->read( $scope_type, $scope_id );

		return null === $row ? CircuitBreakerState::CLOSED : CircuitBreakerState::from( $row['state'] );
	}

	/**
	 * Whether any scope (bot or destination) is currently open. Used by
	 * QueueHealthAlert (WP8); never itself a write path.
	 *
	 * @return bool
	 */
	public function has_any_open(): bool {
		if ( ! $this->schema_health->is_available() ) {
			return false;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::CIRCUIT_BREAKER_TABLE;
		$count = $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE state = %s", CircuitBreakerState::OPEN->value ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		return (int) $count > 0;
	}

	/**
	 * Records a successful attempt: closes the breaker and resets its
	 * consecutive-failure count.
	 *
	 * @param string $scope_type 'bot' or 'destination'.
	 * @param int    $scope_id   The bot or destination's primary key.
	 */
	public function record_success( string $scope_type, int $scope_id ): void {
		$this->write( $scope_type, $scope_id, CircuitBreakerState::CLOSED, 0, null, null );
	}

	/**
	 * Records one RETRYABLE consecutive failure. Opens the breaker once the
	 * threshold is reached (a fresh open, 60-second first cooldown) or, if
	 * already half_open (a failed probe), reopens with an escalating
	 * cooldown reusing RetryPolicy::delay_seconds().
	 *
	 * @param string $scope_type      'bot' or 'destination'.
	 * @param int    $scope_id        The bot or destination's primary key.
	 * @param int    $threshold        Consecutive failures required to open.
	 * @param int    $window_seconds   The observation window.
	 */
	public function record_failure( string $scope_type, int $scope_id, int $threshold, int $window_seconds ): void {
		$row           = $this->read( $scope_type, $scope_id );
		$current_state = null === $row ? CircuitBreakerState::CLOSED : CircuitBreakerState::from( $row['state'] );

		if ( CircuitBreakerState::CLOSED === $current_state ) {
			$consecutive_failures = ( null !== $row && $this->row_updated_within_window( $row, $window_seconds ) )
				? (int) $row['consecutive_failures'] + 1
				: 1;

			if ( $consecutive_failures >= $threshold ) {
				$this->write( $scope_type, $scope_id, CircuitBreakerState::OPEN, $consecutive_failures, $this->now_mysql(), $this->from_now_mysql( self::FIRST_COOLDOWN_SECONDS ) );
				return;
			}

			$this->write( $scope_type, $scope_id, CircuitBreakerState::CLOSED, $consecutive_failures, null, null );
			return;
		}

		// Already OPEN or HALF_OPEN (a failed probe): escalate the cooldown,
		// reusing RetryPolicy::delay_seconds() rather than duplicating
		// backoff math.
		$consecutive_failures = (int) $row['consecutive_failures'] + 1;
		$reopen_number        = max( 1, $consecutive_failures - $threshold );
		$cooldown             = $this->retry_policy->delay_seconds( $reopen_number );

		$this->write(
			$scope_type,
			$scope_id,
			CircuitBreakerState::OPEN,
			$consecutive_failures,
			$row['opened_at'] ?? $this->now_mysql(),
			$this->from_now_mysql( $cooldown )
		);
	}

	/**
	 * Opens the breaker indefinitely, with no automatic half-open probe —
	 * used for TOKEN_INVALID, which does not self-heal with elapsed time.
	 * Only an explicit administrator action (token replace) clears this.
	 *
	 * @param string $scope_type 'bot' or 'destination'.
	 * @param int    $scope_id   The bot or destination's primary key.
	 */
	public function open_indefinitely( string $scope_type, int $scope_id ): void {
		$row = $this->read( $scope_type, $scope_id );

		$this->write( $scope_type, $scope_id, CircuitBreakerState::OPEN, null === $row ? 1 : (int) $row['consecutive_failures'], $this->now_mysql(), null );
	}

	/**
	 * Whether an existing row's last update falls within the given window.
	 *
	 * @param array<string, mixed> $row            The raw database row.
	 * @param int                  $window_seconds The observation window.
	 *
	 * @return bool
	 */
	private function row_updated_within_window( array $row, int $window_seconds ): bool {
		$updated_at = strtotime( $row['updated_at'] . ' UTC' );

		return false !== $updated_at && ( $this->now() - $updated_at ) <= $window_seconds;
	}

	/**
	 * The current time.
	 *
	 * @return int
	 */
	private function now(): int {
		return ( $this->clock )();
	}

	/**
	 * The current time as a MySQL datetime string.
	 *
	 * @return string
	 */
	private function now_mysql(): string {
		return gmdate( 'Y-m-d H:i:s', $this->now() );
	}

	/**
	 * The current time plus an offset, as a MySQL datetime string.
	 *
	 * @param int $seconds_from_now Seconds to add to the current time.
	 *
	 * @return string
	 */
	private function from_now_mysql( int $seconds_from_now ): string {
		return gmdate( 'Y-m-d H:i:s', $this->now() + $seconds_from_now );
	}

	/**
	 * Reads the current state row for a scope, if one exists.
	 *
	 * @param string $scope_type 'bot' or 'destination'.
	 * @param int    $scope_id   The bot or destination's primary key.
	 *
	 * @return array<string, mixed>|null
	 */
	private function read( string $scope_type, int $scope_id ): ?array {
		global $wpdb;

		$table = $wpdb->prefix . Migrator::CIRCUIT_BREAKER_TABLE;

		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE scope_type = %s AND scope_id = %d", $scope_type, $scope_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
	}

	/**
	 * Writes (inserts or replaces) the state row for a scope.
	 *
	 * @param string              $scope_type            'bot' or 'destination'.
	 * @param int                 $scope_id              The bot or destination's primary key.
	 * @param CircuitBreakerState $state                 The new state.
	 * @param int|null            $consecutive_failures  The new consecutive-failure count.
	 * @param string|null         $opened_at              When this open episode began.
	 * @param string|null         $next_probe_at          When a half-open probe next becomes eligible.
	 */
	private function write( string $scope_type, int $scope_id, CircuitBreakerState $state, ?int $consecutive_failures, ?string $opened_at, ?string $next_probe_at ): void {
		global $wpdb;

		$table = $wpdb->prefix . Migrator::CIRCUIT_BREAKER_TABLE;

		// Each nullable column is rendered as an already-safely-escaped,
		// self-contained SQL fragment (a literal NULL, or a single-value
		// prepare() of its own) before being spliced into the outer
		// statement — never a bare %s placeholder for a value that may be
		// null, since a null argument there is not reliably rendered as SQL
		// NULL across supported WordPress core versions.
		$opened_at_sql     = null === $opened_at ? 'NULL' : $wpdb->prepare( '%s', $opened_at );
		$next_probe_at_sql = null === $next_probe_at ? 'NULL' : $wpdb->prepare( '%s', $next_probe_at );

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (scope_type, scope_id, state, consecutive_failures, opened_at, next_probe_at, updated_at) " // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				. "VALUES (%s, %d, %s, %d, {$opened_at_sql}, {$next_probe_at_sql}, %s) " // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- each fragment is itself already a NULL literal or a prepare()d value.
				. 'ON DUPLICATE KEY UPDATE state = VALUES(state), consecutive_failures = VALUES(consecutive_failures), opened_at = VALUES(opened_at), next_probe_at = VALUES(next_probe_at), updated_at = VALUES(updated_at)',
				$scope_type,
				$scope_id,
				$state->value,
				$consecutive_failures ?? 0,
				$this->now_mysql()
			)
		);
	}
}
