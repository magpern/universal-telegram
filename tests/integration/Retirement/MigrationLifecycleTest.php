<?php
/**
 * ADR-0044 forward-only v37 migration lifecycle.
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
final class MigrationLifecycleTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		global $wpdb;

		foreach ( array_merge( Migrator::LEGACY_TABLES, array( Migrator::OPERATOR_IDENTITY_MAP_TABLE ) ) as $table ) {
			$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . $table ); // phpcs:ignore
		}

		delete_option( 'universal_telegram_db_version' );
		delete_option( Migrator::LEGACY_CHAT_RETIRED_OPTION );
	}

	public function test_target_version_is_37_and_monotonic(): void {
		self::assertSame( 37, ( new class( new MigrationLock() ) extends Migrator {
			public function target(): int {
				return $this->target_version();
			}
		} )->target() );
	}

	public function test_fresh_install_creates_only_retained_schema_and_never_a_legacy_table(): void {
		global $wpdb;

		( new Migrator( new MigrationLock() ) )->maybe_migrate();

		self::assertSame( '37', (string) get_option( 'universal_telegram_db_version' ) );
		self::assertTrue( Migrator::table_exists( $wpdb->prefix . Migrator::OPERATOR_IDENTITY_MAP_TABLE ) );
		self::assertTrue( Migrator::table_exists( $wpdb->prefix . Migrator::SUPPORT_CHAT_BINDINGS_TABLE ) );
		self::assertTrue( Migrator::table_exists( $wpdb->prefix . Migrator::OPERATIONAL_ALERT_STATE_TABLE ) );

		foreach ( Migrator::LEGACY_TABLES as $legacy ) {
			self::assertFalse(
				Migrator::table_exists( $wpdb->prefix . $legacy ),
				"fresh install must not create legacy table {$legacy}"
			);
		}

		self::assertNull( get_option( Migrator::LEGACY_CHAT_RETIRED_OPTION, null ), 'fresh install writes no retirement marker' );
	}

	public function test_seeded_v36_upgrade_runs_only_step_37_copies_mappings_and_sets_the_marker(): void {
		global $wpdb;

		// Bring the schema to the retained shape, then simulate a pre-ADR-0044
		// install: db_version 36 + an obsolete operator_identities table with rows.
		( new Migrator( new MigrationLock() ) )->maybe_migrate();
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . Migrator::OPERATOR_IDENTITY_MAP_TABLE ); // phpcs:ignore

		$legacy = $wpdb->prefix . Migrator::OPERATOR_IDENTITIES_TABLE;
		$conv   = $wpdb->prefix . Migrator::CONVERSATIONS_TABLE;
		$wpdb->query( "CREATE TABLE {$legacy} ( id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, wp_user_id BIGINT UNSIGNED NOT NULL, telegram_user_id BIGINT UNSIGNED NOT NULL, telegram_username VARCHAR(255) NULL, created_at DATETIME NOT NULL, created_by BIGINT UNSIGNED NOT NULL, PRIMARY KEY (id) )" ); // phpcs:ignore
		$wpdb->query( "CREATE TABLE {$conv} ( id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, PRIMARY KEY (id) )" ); // phpcs:ignore
		$wpdb->query( "INSERT INTO {$legacy} (wp_user_id, telegram_user_id, telegram_username, created_at, created_by) VALUES (7, 100, 'a', '2026-01-01 00:00:00', 1), (8, 200, NULL, '2026-01-01 00:00:00', 1)" ); // phpcs:ignore

		update_option( 'universal_telegram_db_version', 36 );

		( new Migrator( new MigrationLock() ) )->maybe_migrate();

		self::assertSame( '37', (string) get_option( 'universal_telegram_db_version' ) );

		$map = $wpdb->prefix . Migrator::OPERATOR_IDENTITY_MAP_TABLE;
		self::assertTrue( Migrator::table_exists( $map ) );
		self::assertSame( '2', (string) $wpdb->get_var( "SELECT COUNT(*) FROM {$map}" ) ); // phpcs:ignore
		self::assertSame( '100', (string) $wpdb->get_var( "SELECT telegram_user_id FROM {$map} WHERE wp_user_id = 7" ) ); // phpcs:ignore

		self::assertNotNull( get_option( Migrator::LEGACY_CHAT_RETIRED_OPTION, null ), 'upgrade with obsolete tables sets the marker' );
		self::assertTrue( Migrator::table_exists( $legacy ), 'step 37 drops nothing' );
		self::assertTrue( Migrator::table_exists( $conv ), 'step 37 drops nothing' );
		self::assertTrue( OperatorIdentityMapMigration::verify_bijection()->holds() );
	}
}
