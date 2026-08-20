<?php
/**
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Tests\Integration\Support;

use UniversalTelegram\Persistence\MigrationFailedException;
use UniversalTelegram\Persistence\MigrationFailureCode;
use UniversalTelegram\Persistence\Migrator;

/**
 * Test-only migrator appending a synthetic step 2 with two statements: an
 * idempotent CREATE TABLE IF NOT EXISTS that always succeeds, and a second
 * "statement" that fails on its first invocation and succeeds on any
 * later one — modelling a real second-statement failure and a safe,
 * idempotent retry, without touching the one real production step.
 */
final class FailingMultiStatementMigrator extends Migrator {

	/**
	 * @var bool
	 */
	public static bool $second_statement_should_fail = true;

	protected function target_version(): int {
		return 2;
	}

	protected function run_step( int $number ): void {
		if ( 2 !== $number ) {
			parent::run_step( $number );
			return;
		}

		global $wpdb;

		$table = $wpdb->prefix . 'universal_telegram_test_marker';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name, never user input.
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$table} (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, PRIMARY KEY (id)) {$wpdb->get_charset_collate()}" );

		if ( self::$second_statement_should_fail ) {
			self::$second_statement_should_fail = false;
			throw new MigrationFailedException( MigrationFailureCode::STEP_FAILED );
		}
	}
}
