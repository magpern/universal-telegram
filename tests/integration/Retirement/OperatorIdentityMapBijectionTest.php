<?php
/**
 * ADR-0044 §4 operator-identity-map exact bijection.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Tests\Integration\Retirement;

use UniversalTelegram\Persistence\MigrationLock;
use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Persistence\OperatorIdentityMapMigration;
use WP_UnitTestCase;

/**
 * @coversNothing
 */
final class OperatorIdentityMapBijectionTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		global $wpdb;

		( new Migrator( new MigrationLock() ) )->maybe_migrate();

		$this->source = $wpdb->prefix . Migrator::OPERATOR_IDENTITIES_TABLE;
		$this->map    = $wpdb->prefix . Migrator::OPERATOR_IDENTITY_MAP_TABLE;

		$wpdb->query( 'DROP TABLE IF EXISTS ' . $this->source ); // phpcs:ignore
		$wpdb->query( "CREATE TABLE {$this->source} ( id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, wp_user_id BIGINT UNSIGNED NOT NULL, telegram_user_id BIGINT UNSIGNED NOT NULL, telegram_username VARCHAR(255) NULL, created_at DATETIME NOT NULL, created_by BIGINT UNSIGNED NOT NULL, PRIMARY KEY (id) )" ); // phpcs:ignore
		$wpdb->query( "TRUNCATE TABLE {$this->map}" ); // phpcs:ignore
	}

	private string $source = '';
	private string $map    = '';

	private function seed_source( int $wp, int $tg ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$this->source} (wp_user_id, telegram_user_id, telegram_username, created_at, created_by) VALUES (%d, %d, NULL, '2026-01-01 00:00:00', 1)", $wp, $tg ) ); // phpcs:ignore
	}

	private function seed_map( int $wp, int $tg ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$this->map} (wp_user_id, telegram_user_id, telegram_username, created_at, created_by) VALUES (%d, %d, NULL, '2026-01-01 00:00:00', 1)", $wp, $tg ) ); // phpcs:ignore
	}

	public function test_a_clean_copy_holds_with_no_unreachable_extras(): void {
		$this->seed_source( 7, 100 );
		$this->seed_source( 8, 200 );
		$this->seed_map( 7, 100 );
		$this->seed_map( 8, 200 );

		$report = OperatorIdentityMapMigration::verify_bijection();

		self::assertTrue( $report->holds() );
		self::assertSame( array(), $report->unreachable_extras() );
	}

	public function test_idempotent_rerun_is_stable(): void {
		$this->seed_source( 7, 100 );
		$this->seed_map( 7, 100 );

		self::assertTrue( OperatorIdentityMapMigration::verify_bijection()->holds() );
		self::assertTrue( OperatorIdentityMapMigration::verify_bijection()->holds() );
	}

	public function test_same_wp_user_conflicting_telegram_user_is_a_conflict(): void {
		$this->seed_source( 7, 100 );
		$this->seed_map( 7, 999 );

		$report = OperatorIdentityMapMigration::verify_bijection();

		self::assertFalse( $report->holds() );
		self::assertStringContainsString( 'conflict', implode( ' ', $report->mismatches() ) );
	}

	public function test_same_telegram_user_conflicting_wp_user_is_a_conflict(): void {
		$this->seed_source( 7, 100 );
		$this->seed_map( 42, 100 );

		self::assertFalse( OperatorIdentityMapMigration::verify_bijection()->holds() );
	}

	public function test_missing_target_pair_is_a_failure(): void {
		$this->seed_source( 7, 100 );

		$report = OperatorIdentityMapMigration::verify_bijection();

		self::assertFalse( $report->holds() );
		self::assertStringContainsString( 'missing', implode( ' ', $report->mismatches() ) );
	}

	public function test_unreachable_extra_map_row_is_permitted_and_reported(): void {
		$this->seed_source( 7, 100 );
		$this->seed_map( 7, 100 );
		$this->seed_map( 55, 5555 );

		$report = OperatorIdentityMapMigration::verify_bijection();

		self::assertTrue( $report->holds() );
		self::assertCount( 1, $report->unreachable_extras() );
		self::assertSame( 55, $report->unreachable_extras()[0]['wp_user_id'] );
	}
}
