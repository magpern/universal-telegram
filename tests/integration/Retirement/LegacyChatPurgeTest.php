<?php
/**
 * ADR-0044 §5 guarded legacy-chat purge.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Tests\Integration\Retirement;

use UniversalTelegram\Persistence\LegacyChatPurge;
use UniversalTelegram\Persistence\MigrationLock;
use UniversalTelegram\Persistence\Migrator;
use WP_UnitTestCase;

/**
 * @coversNothing
 */
final class LegacyChatPurgeTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		global $wpdb;

		( new Migrator( new MigrationLock() ) )->maybe_migrate();

		// Simulate an upgraded install: recreate a few obsolete legacy tables
		// with content, plus a seeded operator_identities row + its map row.
		foreach ( array( Migrator::CONVERSATIONS_TABLE, Migrator::AI_CONFIG_TABLE, Migrator::CUTOVER_RUNS_TABLE, Migrator::OPERATOR_IDENTITIES_TABLE ) as $table ) {
			$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . $table ); // phpcs:ignore
			$wpdb->query( 'CREATE TABLE ' . $wpdb->prefix . $table . ' ( id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, wp_user_id BIGINT UNSIGNED NULL, telegram_user_id BIGINT UNSIGNED NULL, telegram_username VARCHAR(255) NULL, created_at DATETIME NULL, created_by BIGINT UNSIGNED NULL, PRIMARY KEY (id) )' ); // phpcs:ignore
		}

		$src = $wpdb->prefix . Migrator::OPERATOR_IDENTITIES_TABLE;
		$map = $wpdb->prefix . Migrator::OPERATOR_IDENTITY_MAP_TABLE;
		$wpdb->query( "TRUNCATE TABLE {$map}" ); // phpcs:ignore
		$wpdb->query( "INSERT INTO {$src} (wp_user_id, telegram_user_id, created_at, created_by) VALUES (7, 100, '2026-01-01 00:00:00', 1)" ); // phpcs:ignore
		$wpdb->query( "INSERT INTO {$map} (wp_user_id, telegram_user_id, telegram_username, created_at, created_by) VALUES (7, 100, NULL, '2026-01-01 00:00:00', 1)" ); // phpcs:ignore

		update_option( Migrator::LEGACY_CHAT_RETIRED_OPTION, gmdate( 'c' ) );
		update_option( 'universal_telegram_ai_settings', array( 'x' => 1 ) );
	}

	public function test_dry_run_lists_legacy_objects_and_touches_nothing(): void {
		global $wpdb;

		$report = ( new LegacyChatPurge() )->dry_run();

		self::assertTrue( $report->ok() );
		self::assertStringContainsString( 'would drop table', implode( "\n", $report->lines() ) );
		self::assertTrue( Migrator::table_exists( $wpdb->prefix . Migrator::CONVERSATIONS_TABLE ), 'dry run drops nothing' );
		self::assertNotNull( get_option( 'universal_telegram_ai_settings', null ) );
	}

	public function test_real_run_drops_only_legacy_and_preserves_transport_and_credentials(): void {
		global $wpdb;

		// A bot row must survive.
		$bots = $wpdb->prefix . Migrator::BOTS_TABLE;
		$wpdb->query( "INSERT INTO {$bots} (bot_uuid, name, token_ciphertext, webhook_secret_ciphertext, webhook_registration_state, status, created_at, updated_at) VALUES ('11111111-1111-1111-1111-111111111111', 'b', 'CIPHER', 'CIPHER', 'unregistered', 'active', '2026-01-01 00:00:00', '2026-01-01 00:00:00')" ); // phpcs:ignore

		$report = ( new LegacyChatPurge() )->run();

		self::assertTrue( $report->ok(), implode( "\n", $report->lines() ) );

		foreach ( Migrator::LEGACY_TABLES as $legacy ) {
			self::assertFalse( Migrator::table_exists( $wpdb->prefix . $legacy ), "legacy table {$legacy} must be dropped" );
		}
		self::assertNull( get_option( 'universal_telegram_ai_settings', null ) );
		self::assertNull( get_option( Migrator::LEGACY_CHAT_RETIRED_OPTION, null ) );
		self::assertSame( '37', (string) get_option( 'universal_telegram_db_version' ) );

		self::assertTrue( Migrator::table_exists( $bots ) );
		self::assertSame( 'CIPHER', (string) $wpdb->get_var( "SELECT token_ciphertext FROM {$bots} LIMIT 1" ) ); // phpcs:ignore
		self::assertTrue( Migrator::table_exists( $wpdb->prefix . Migrator::SUPPORT_CHAT_BINDINGS_TABLE ) );
		self::assertTrue( Migrator::table_exists( $wpdb->prefix . Migrator::OPERATOR_IDENTITY_MAP_TABLE ) );
		self::assertTrue( Migrator::table_exists( $wpdb->prefix . Migrator::OPERATIONAL_ALERT_STATE_TABLE ) );
	}

	public function test_bijection_conflict_aborts_purge_with_no_destructive_side_effect(): void {
		global $wpdb;

		// Break the bijection: same telegram_user_id, different wp_user_id.
		$map = $wpdb->prefix . Migrator::OPERATOR_IDENTITY_MAP_TABLE;
		$wpdb->query( "UPDATE {$map} SET wp_user_id = 999 WHERE telegram_user_id = 100" ); // phpcs:ignore

		$report = ( new LegacyChatPurge() )->run();

		self::assertFalse( $report->ok() );
		self::assertStringContainsString( 'ABORTED', implode( "\n", $report->lines() ) );

		foreach ( Migrator::LEGACY_TABLES as $legacy ) {
			if ( in_array( $legacy, array( Migrator::CONVERSATIONS_TABLE, Migrator::AI_CONFIG_TABLE, Migrator::CUTOVER_RUNS_TABLE, Migrator::OPERATOR_IDENTITIES_TABLE ), true ) ) {
				self::assertTrue( Migrator::table_exists( $wpdb->prefix . $legacy ), "purge aborted — {$legacy} must remain" );
			}
		}
		self::assertNotNull( get_option( Migrator::LEGACY_CHAT_RETIRED_OPTION, null ) );
		self::assertNotNull( get_option( 'universal_telegram_ai_settings', null ) );
	}
}
