<?php
/**
 * Plugin uninstall.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Core\Lifecycle;

use ActionScheduler;
use ActionScheduler_Store;
use Exception;
use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;
use UniversalTelegram\Core\Configuration\Settings;
use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Queue\WorkerRunner;

/**
 * Always, unconditionally: revokes the plugin's capability from every
 * role, and cancels every pending action in the plugin's own Action
 * Scheduler group. Only when the operator has explicitly opted into full
 * data removal: drops the plugin's own table, deletes its own options,
 * and removes historical action and log rows in its own group — using
 * only Action Scheduler's own public store API, exactly as its own
 * official WP-CLI delete command does, never a raw statement against its
 * shared tables (docs/adr and the M00 plan section 4.10).
 */
final class Uninstaller {

	private const HISTORICAL_BATCH_SIZE = 1000;

	/**
	 * Runs the uninstall routine.
	 */
	public function run(): void {
		( new CapabilityRegistrar() )->revoke_from_all_roles();

		// Action Scheduler's own docblock claims $hook is a required
		// string, but its actual implementation explicitly tolerates
		// null via empty() checks — cancelling every action in the given
		// group regardless of hook, exactly as intended here.
		// phpcs:ignore Squiz.Commenting.InlineComment.InvalidEndChar
		// @phpstan-ignore argument.type
		as_unschedule_all_actions( null, array(), WorkerRunner::GROUP );

		$settings = ( new Settings() )->get();

		if ( true !== $settings['remove_data_on_uninstall'] ) {
			return;
		}

		$this->drop_audit_table();
		delete_option( Settings::OPTION_NAME );
		delete_option( 'universal_telegram_db_version' );

		$this->remove_historical_actions();
	}

	/**
	 * Drops the plugin's own single table. Never touches any table any
	 * other dependency, including Action Scheduler itself, owns.
	 */
	private function drop_audit_table(): void {
		global $wpdb;

		$table = $wpdb->prefix . Migrator::AUDIT_LOG_TABLE;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name, never user input.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}

	/**
	 * Removes every historical action and log row in the plugin's own
	 * group, covering complete, failed, canceled, and stale in-progress
	 * actions, paginated exactly as Action Scheduler's own official
	 * WP-CLI delete command does. Each successful delete_action() call
	 * fires Action Scheduler's own action_scheduler_deleted_action hook,
	 * to which its own default logger has already attached its own log
	 * cleanup, so no separate log deletion is needed here.
	 */
	private function remove_historical_actions(): void {
		if ( ! class_exists( ActionScheduler::class ) ) {
			return;
		}

		$statuses = array(
			ActionScheduler_Store::STATUS_COMPLETE,
			ActionScheduler_Store::STATUS_FAILED,
			ActionScheduler_Store::STATUS_CANCELED,
			ActionScheduler_Store::STATUS_RUNNING,
		);

		do {
			$action_ids = ActionScheduler::store()->query_actions(
				array(
					'group'    => WorkerRunner::GROUP,
					'status'   => $statuses,
					'per_page' => self::HISTORICAL_BATCH_SIZE,
					'orderby'  => 'none',
				),
				'select'
			);

			foreach ( $action_ids as $action_id ) {
				try {
					ActionScheduler::store()->delete_action( $action_id );
				} catch ( Exception $exception ) {
					// Already removed by another process since it was
					// queried; tolerate and continue, exactly as Action
					// Scheduler's own official delete command does.
					continue;
				}
			}
		} while ( ! empty( $action_ids ) );
	}
}
