<?php
/**
 * Schema migration runner.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Persistence;

/**
 * Runs schema changes as numbered, ordered steps using raw $wpdb->query()
 * data-definition statements, never dbDelta(). The schema-version option
 * advances only immediately after a step's own statements and its own
 * postcondition check both succeed, never partially.
 *
 * Not declared final: tests/integration/Support/FailingMultiStatementMigrator
 * extends this to exercise the generic step-runner's partial-failure
 * behaviour against a synthetic multi-statement step, without touching the
 * one real production step.
 */
class Migrator {

	public const AUDIT_LOG_TABLE = 'universal_telegram_audit_log';

	private const DB_VERSION_OPTION = 'universal_telegram_db_version';

	/**
	 * Coordinates concurrent migration attempts.
	 *
	 * @var MigrationLock
	 */
	private MigrationLock $lock;

	/**
	 * Constructor.
	 *
	 * @param MigrationLock $lock Coordinates concurrent migration attempts.
	 */
	public function __construct( MigrationLock $lock ) {
		$this->lock = $lock;
	}

	/**
	 * The highest step number this migrator knows how to run. Overridable
	 * by test doubles that append a synthetic step.
	 *
	 * @return int
	 */
	protected function target_version(): int {
		return 1;
	}

	/**
	 * Runs whatever pending steps exist, coordinated by the migration lock.
	 * Never partially advances the schema-version option.
	 *
	 * @throws MigrationFailedException If the lock cannot be acquired while
	 *                                   the schema is still behind, or a
	 *                                   step's statements or its
	 *                                   postcondition check fail.
	 */
	public function maybe_migrate(): void {
		$current_version = (int) get_option( self::DB_VERSION_OPTION, 0 );

		if ( $current_version >= $this->target_version() ) {
			return;
		}

		$handle = $this->lock->acquire();

		if ( null === $handle ) {
			// Another process is migrating (or holds a fresh lock). If the
			// schema is still behind by the time we would otherwise give up,
			// this request's own schema-touching operations must not
			// proceed as though it is available.
			$current_version = (int) get_option( self::DB_VERSION_OPTION, 0 );

			if ( $current_version < $this->target_version() ) {
				throw new MigrationFailedException( MigrationFailureCode::LOCK_UNAVAILABLE );
			}

			return;
		}

		try {
			$this->run_pending_steps( $current_version );
		} finally {
			$this->lock->release( $handle );
		}
	}

	/**
	 * Runs every step between the recorded version and the target version.
	 *
	 * @param int $from_version The schema version already recorded.
	 *
	 * @throws MigrationFailedException If any pending step fails.
	 */
	private function run_pending_steps( int $from_version ): void {
		$target = $this->target_version();

		for ( $number = $from_version + 1; $number <= $target; $number++ ) {
			$this->run_step( $number );
			update_option( self::DB_VERSION_OPTION, $number );
		}
	}

	/**
	 * Runs one step's statements and its postcondition check. Overridable
	 * by test doubles; production code only ever reaches the step 1 case.
	 *
	 * @param int $number The step number to run.
	 *
	 * @throws MigrationFailedException If the step's postcondition check
	 *                                   fails, or the step number is unknown.
	 */
	protected function run_step( int $number ): void {
		if ( 1 === $number ) {
			$this->step_1_create_audit_log_table();

			if ( ! $this->verify_step_1() ) {
				throw new MigrationFailedException( MigrationFailureCode::POSTCONDITION_FAILED );
			}

			return;
		}

		throw new MigrationFailedException( MigrationFailureCode::STEP_FAILED );
	}

	/**
	 * Creates the plugin's one database table.
	 */
	private function step_1_create_audit_log_table(): void {
		global $wpdb;

		$table           = $wpdb->prefix . self::AUDIT_LOG_TABLE;
		$charset_collate = $wpdb->get_charset_collate();

		// $wpdb->prepare() cannot parameterize identifiers or an entire DDL
		// statement; the table name and charset/collation clause are a
		// fixed plugin constant and WordPress' own core value, never user
		// input.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				occurred_at DATETIME NOT NULL,
				actor_type VARCHAR(32) NOT NULL,
				actor_id BIGINT UNSIGNED NULL,
				action VARCHAR(191) NOT NULL,
				context LONGTEXT NULL,
				privacy_classification VARCHAR(16) NOT NULL,
				PRIMARY KEY (id),
				KEY occurred_at (occurred_at),
				KEY action (action)
			) {$charset_collate}"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Re-queries the database's own information schema to confirm the
	 * table genuinely matches what step 1 intended.
	 *
	 * @return bool
	 */
	private function verify_step_1(): bool {
		global $wpdb;

		$table    = $wpdb->prefix . self::AUDIT_LOG_TABLE;
		$expected = array( 'id', 'occurred_at', 'actor_type', 'actor_id', 'action', 'context', 'privacy_classification' );

		$columns = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
				$wpdb->dbname,
				$table
			)
		);

		return array() === array_diff( $expected, $columns );
	}
}
