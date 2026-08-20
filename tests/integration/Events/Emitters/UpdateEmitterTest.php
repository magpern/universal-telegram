<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Events\Emitters;

use UniversalTelegram\Persistence\Migrator;
use WP_UnitTestCase;

final class UpdateEmitterTest extends WP_UnitTestCase {

	private function count_for( string $event_type ): int {
		global $wpdb;

		$table = $wpdb->prefix . Migrator::EVENT_HISTORY_TABLE;

		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE event_type = %s", $event_type ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	public function test_a_pending_core_update_emits_update_available(): void {
		$update           = new \stdClass();
		$update->response = 'upgrade';
		$update->version  = '99.9';

		$transient          = new \stdClass();
		$transient->updates = array( $update );
		set_site_transient( 'update_core', $transient );

		do_action( 'universal_telegram_check_updates' );

		$this->assertSame( 1, $this->count_for( 'wordpress.update_available' ) );

		delete_site_transient( 'update_core' );
	}

	public function test_checking_twice_on_the_same_day_does_not_duplicate(): void {
		$update           = new \stdClass();
		$update->response = 'upgrade';
		$update->version  = '99.9';

		$transient          = new \stdClass();
		$transient->updates = array( $update );
		set_site_transient( 'update_core', $transient );

		do_action( 'universal_telegram_check_updates' );
		do_action( 'universal_telegram_check_updates' );

		$this->assertSame( 1, $this->count_for( 'wordpress.update_available' ) );

		delete_site_transient( 'update_core' );
	}

	public function test_update_completed_is_emitted(): void {
		do_action(
			'upgrader_process_complete',
			null,
			array(
				'action' => 'update',
				'type'   => 'plugin',
				'plugins' => array( 'some-plugin/some-plugin.php' ),
			)
		);

		$this->assertSame( 1, $this->count_for( 'wordpress.update_completed' ) );
	}
}
