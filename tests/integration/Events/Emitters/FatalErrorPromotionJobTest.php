<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Events\Emitters;

use UniversalTelegram\Events\Emitters\FatalErrorPromotionJob;
use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Persistence\SchemaHealth;
use WP_UnitTestCase;

final class FatalErrorPromotionJobTest extends WP_UnitTestCase {

	private function insert_pending_marker( string $location_hash ): int {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . Migrator::FATAL_ERROR_MARKERS_TABLE,
			array(
				'error_type'    => 'E_ERROR',
				'location_hash' => $location_hash,
				'status'        => 'pending',
				'occurred_at'   => current_time( 'mysql', true ),
				'created_at'    => current_time( 'mysql', true ),
			)
		);

		return (int) $wpdb->insert_id;
	}

	public function test_a_pending_marker_is_promoted_and_emits_fatal_error_once(): void {
		global $wpdb;

		$hash = hash( 'sha256', 'test-loc-1' );
		$this->insert_pending_marker( $hash );

		( new FatalErrorPromotionJob( new SchemaHealth() ) )->run();

		$markers_table = $wpdb->prefix . Migrator::FATAL_ERROR_MARKERS_TABLE;
		$status        = $wpdb->get_var(
			$wpdb->prepare( "SELECT status FROM {$markers_table} WHERE location_hash = %s", $hash ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		$this->assertSame( 'promoted', $status );

		$history_table = $wpdb->prefix . Migrator::EVENT_HISTORY_TABLE;
		$count         = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$history_table} WHERE event_type = 'wordpress.fatal_error'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$this->assertSame( 1, $count );
	}

	public function test_running_twice_against_an_already_promoted_row_never_emits_a_second_notification(): void {
		global $wpdb;

		$hash = hash( 'sha256', 'test-loc-2' );
		$this->insert_pending_marker( $hash );

		$job = new FatalErrorPromotionJob( new SchemaHealth() );
		$job->run();
		$job->run();

		$history_table = $wpdb->prefix . Migrator::EVENT_HISTORY_TABLE;
		$count         = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$history_table} WHERE event_type = 'wordpress.fatal_error'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$this->assertSame( 1, $count );
	}

	public function test_the_promoted_event_never_contains_message_text_stack_trace_or_raw_path(): void {
		global $wpdb;

		$hash = hash( 'sha256', '/var/www/html/wp-content/plugins/some-plugin/file.php:42' );
		$this->insert_pending_marker( $hash );

		( new FatalErrorPromotionJob( new SchemaHealth() ) )->run();

		$history_table = $wpdb->prefix . Migrator::EVENT_HISTORY_TABLE;
		$row           = $wpdb->get_row(
			"SELECT projected_fields_json FROM {$history_table} WHERE event_type = 'wordpress.fatal_error' ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);

		$this->assertNotNull( $row );
		$this->assertStringNotContainsString( '/var/www', $row['projected_fields_json'] );
		$this->assertStringNotContainsString( '.php', $row['projected_fields_json'] );

		$projected = json_decode( $row['projected_fields_json'], true );
		$this->assertSame(
			array(
				'payload' => array(
					'error_type'    => 'E_ERROR',
					'location_hash' => $hash,
				),
			),
			$projected
		);
	}
}
