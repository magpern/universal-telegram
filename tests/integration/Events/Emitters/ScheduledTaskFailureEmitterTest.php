<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Events\Emitters;

use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Queue\WorkerRunner;
use WP_UnitTestCase;

final class ScheduledTaskFailureEmitterTest extends WP_UnitTestCase {

	private function count_for( string $event_type ): int {
		global $wpdb;

		$table = $wpdb->prefix . Migrator::EVENT_HISTORY_TABLE;

		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE event_type = %s", $event_type ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	public function test_a_failed_action_outside_the_plugins_own_group_is_emitted(): void {
		$action_id = as_enqueue_async_action( 'some_other_plugin_hook', array(), 'some-other-group' );

		do_action( 'action_scheduler_failed_action', $action_id, null );

		$this->assertSame( 1, $this->count_for( 'wordpress.scheduled_task_failed' ) );
	}

	public function test_excludes_universal_telegram_group_actions(): void {
		$action_id = as_enqueue_async_action( 'some_ut_job', array(), WorkerRunner::GROUP );

		do_action( 'action_scheduler_failed_action', $action_id, null );

		$this->assertSame( 0, $this->count_for( 'wordpress.scheduled_task_failed' ) );
	}
}
