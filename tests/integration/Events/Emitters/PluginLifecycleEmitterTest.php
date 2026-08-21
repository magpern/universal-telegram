<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Events\Emitters;

use UniversalTelegram\Persistence\Migrator;
use WP_UnitTestCase;

final class PluginLifecycleEmitterTest extends WP_UnitTestCase {

	private function history_row_for( string $event_type ): ?array {
		global $wpdb;

		$table = $wpdb->prefix . Migrator::EVENT_HISTORY_TABLE;

		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE event_type = %s ORDER BY id DESC LIMIT 1", $event_type ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
	}

	public function test_plugin_activated_is_emitted(): void {
		do_action( 'activated_plugin', 'some-plugin/some-plugin.php', false );

		$row = $this->history_row_for( 'wordpress.plugin_activated' );
		$this->assertNotNull( $row );
		$projected = json_decode( $row['projected_fields_json'], true );
		$this->assertSame( 'some-plugin/some-plugin.php', $projected['payload']['plugin'] );
	}

	public function test_plugin_deactivated_is_emitted(): void {
		do_action( 'deactivated_plugin', 'some-plugin/some-plugin.php', false );

		$this->assertNotNull( $this->history_row_for( 'wordpress.plugin_deactivated' ) );
	}

	public function test_two_activations_of_the_same_plugin_are_independent_occurrences(): void {
		global $wpdb;

		$table = $wpdb->prefix . Migrator::EVENT_HISTORY_TABLE;
		$wpdb->query( "DELETE FROM {$table} WHERE event_type = 'wordpress.plugin_activated'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		do_action( 'activated_plugin', 'some-plugin/some-plugin.php', false );
		do_action( 'activated_plugin', 'some-plugin/some-plugin.php', false );

		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE event_type = 'wordpress.plugin_activated'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$this->assertSame( 2, $count );
	}
}
