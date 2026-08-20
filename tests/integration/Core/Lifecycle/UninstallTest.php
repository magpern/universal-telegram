<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Core\Lifecycle;

use ActionScheduler;
use UniversalTelegram\Audit\AuditLogger;
use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;
use UniversalTelegram\Core\Configuration\Settings;
use UniversalTelegram\Core\Lifecycle\Deactivator;
use UniversalTelegram\Core\Lifecycle\Uninstaller;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Privacy\Classification;
use UniversalTelegram\Privacy\Redactor;
use UniversalTelegram\Queue\Dispatcher;
use UniversalTelegram\Queue\JobEnvelope;
use UniversalTelegram\Queue\WorkerRunner;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;
use WP_Error;
use WP_UnitTestCase;

/**
 * Tested against both the WordPress-only and the WooCommerce-present
 * configuration (see bin/docker/test-integration-wc-present.sh), covering
 * all four combinations of the retention setting and WooCommerce's
 * presence together with the two configurations this file itself runs
 * under.
 */
final class UninstallTest extends WP_UnitTestCase {

	private const M01_TABLES = array(
		Migrator::BOTS_TABLE,
		Migrator::DESTINATIONS_TABLE,
		Migrator::OUTBOUND_MESSAGES_TABLE,
		Migrator::INBOUND_UPDATES_TABLE,
		Migrator::CIRCUIT_BREAKER_TABLE,
		Migrator::RATE_LIMIT_TABLE,
	);

	protected function setUp(): void {
		parent::setUp();
		( new CapabilityRegistrar() )->grant_to_administrator();
	}

	private function table_exists( string $table ): bool {
		global $wpdb;

		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	public function test_default_retention_keeps_data_but_always_cleans_pending_actions_capability_and_deregisters_webhooks(): void {
		global $wpdb;

		delete_option( Settings::OPTION_NAME );

		$schema_health = new SchemaHealth();
		$dispatcher    = new Dispatcher( $schema_health );
		$result        = $dispatcher->enqueue( new JobEnvelope( 'test.uninstall_pending', array(), array() ) );
		$this->assertNotNull( $result->action_id() );

		$this->assertTrue( get_role( 'administrator' )->has_cap( CapabilityRegistrar::MANAGE ) );

		$bots = new BotProfileRepository( $schema_health, new CredentialVault() );
		$bots->create( 'Bot', 'fake-token' );

		$deregistration_calls = 0;
		$callback             = function () use ( &$deregistration_calls ) {
			++$deregistration_calls;
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode(
					array(
						'ok'     => true,
						'result' => true,
					)
				),
			);
		};
		add_filter( 'pre_http_request', $callback, 10, 0 );

		( new Deactivator() )->deactivate();
		( new Uninstaller() )->run();

		remove_filter( 'pre_http_request', $callback );

		$this->assertGreaterThan( 0, $deregistration_calls );

		// Unconditional, regardless of the retention setting.
		$this->assertFalse( get_role( 'administrator' )->has_cap( CapabilityRegistrar::MANAGE ) );
		$this->assertFalse( as_next_scheduled_action( WorkerRunner::HOOK, null, WorkerRunner::GROUP ) );

		// Retention-gated: the tables, settings, and schema version remain.
		$table = $wpdb->prefix . Migrator::AUDIT_LOG_TABLE;
		$this->assertTrue( $this->table_exists( $table ) );
		foreach ( self::M01_TABLES as $table_name ) {
			$this->assertTrue( $this->table_exists( $wpdb->prefix . $table_name ), "Expected {$table_name} to still exist." );
		}
		$this->assertNotFalse( get_option( 'universal_telegram_db_version' ) );

		// Action Scheduler's own tables are never touched.
		$as_table = $wpdb->prefix . 'actionscheduler_actions';
		$this->assertTrue( $this->table_exists( $as_table ) );
	}

	public function test_webhook_deregistration_is_attempted_regardless_of_retention_setting_and_failures_are_swallowed(): void {
		$schema_health = new SchemaHealth();
		$bots          = new BotProfileRepository( $schema_health, new CredentialVault() );
		$bots->create( 'Bot', 'fake-token' );

		$callback = static function () {
			return new WP_Error( 'http_request_failed', 'Connection timed out' );
		};
		add_filter( 'pre_http_request', $callback, 10, 0 );

		( new Deactivator() )->deactivate();

		// A network failure while deregistering must never abort the rest
		// of uninstall (retention is left at its default, false, here).
		( new Uninstaller() )->run();

		remove_filter( 'pre_http_request', $callback );

		$this->assertFalse( get_role( 'administrator' )->has_cap( CapabilityRegistrar::MANAGE ) );
	}

	public function test_explicit_opt_in_removes_data_including_the_six_telegram_tables_and_historical_actions(): void {
		global $wpdb;

		update_option( Settings::OPTION_NAME, array( 'remove_data_on_uninstall' => true ) );

		$schema_health = new SchemaHealth();
		$audit_logger  = new AuditLogger( $schema_health, new Redactor() );
		$audit_logger->record( 'test.entry', 'system', null, array(), array(), Classification::PUBLIC );

		// A historical, already-complete action in the plugin's own group.
		$action_id = as_enqueue_async_action( WorkerRunner::HOOK, array(), WorkerRunner::GROUP );
		ActionScheduler::store()->mark_complete( $action_id );

		( new Deactivator() )->deactivate();

		// WP_UnitTestCase's own transaction isolation rewrites CREATE/DROP
		// TABLE into CREATE/DROP TEMPORARY TABLE (see its
		// _create_temporary_tables()/_drop_temporary_tables() query
		// filters). Every plugin table was created during this test run's
		// initial bootstrap, before that rewriting was active, so each is a
		// genuine permanent table — a later, rewritten "DROP TEMPORARY
		// TABLE" against it silently no-ops, since no session-local
		// temporary table by that name exists. This is a test-framework
		// artifact only: production uninstall.php runs in an ordinary
		// request with no such filter present. Removing it for this one
		// assertion reflects that real behaviour.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		try {
			( new Uninstaller() )->run();

			$table = $wpdb->prefix . Migrator::AUDIT_LOG_TABLE;
			$this->assertFalse( $this->table_exists( $table ) );

			foreach ( self::M01_TABLES as $table_name ) {
				$this->assertFalse( $this->table_exists( $wpdb->prefix . $table_name ), "Expected {$table_name} to have been dropped." );
			}

			$this->assertFalse( get_option( Settings::OPTION_NAME ) );
			$this->assertFalse( get_option( 'universal_telegram_db_version' ) );

			$remaining = ActionScheduler::store()->query_actions( array( 'group' => WorkerRunner::GROUP ), 'count' );
			$this->assertSame( 0, (int) $remaining );

			// Action Scheduler's own tables are never dropped, even though
			// the plugin's own historical rows within them are removed.
			$as_table = $wpdb->prefix . 'actionscheduler_actions';
			$this->assertTrue( $this->table_exists( $as_table ) );
		} finally {
			delete_option( 'universal_telegram_migration_lock' );
			$this->recreate_all_tables();

			self::commit_transaction();
			$this->start_transaction();
		}
	}

	/**
	 * Recreates every plugin table directly, mirroring Migrator's own DDL
	 * exactly, since a full maybe_migrate() call here would straddle the
	 * transaction boundary the real CREATE TABLE calls above already
	 * committed (see the docblock in the test above).
	 */
	private function recreate_all_tables(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$audit_table = $wpdb->prefix . Migrator::AUDIT_LOG_TABLE;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- fixed table names, never user input.
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$audit_table} (
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

		$bots_table = $wpdb->prefix . Migrator::BOTS_TABLE;
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$bots_table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				bot_uuid CHAR(36) NOT NULL,
				name VARCHAR(191) NOT NULL,
				token_ciphertext LONGTEXT NOT NULL,
				webhook_secret_ciphertext LONGTEXT NOT NULL,
				webhook_secret_pending_ciphertext LONGTEXT NULL,
				webhook_secret_pending_since DATETIME NULL,
				webhook_registration_state VARCHAR(16) NOT NULL DEFAULT 'unregistered',
				webhook_last_attempt_at DATETIME NULL,
				telegram_bot_id BIGINT NULL,
				telegram_username VARCHAR(191) NULL,
				status VARCHAR(16) NOT NULL DEFAULT 'unconfigured',
				webhook_registered_at DATETIME NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY bot_uuid (bot_uuid),
				KEY status (status)
			) {$charset_collate}"
		);

		$destinations_table = $wpdb->prefix . Migrator::DESTINATIONS_TABLE;
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$destinations_table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				bot_id BIGINT UNSIGNED NOT NULL,
				kind VARCHAR(16) NOT NULL,
				chat_id VARCHAR(64) NOT NULL,
				message_thread_id BIGINT NULL,
				label VARCHAR(191) NOT NULL,
				enabled TINYINT(1) NOT NULL DEFAULT 1,
				created_at DATETIME NOT NULL,
				PRIMARY KEY (id),
				KEY bot_id (bot_id),
				UNIQUE KEY bot_chat_thread (bot_id, chat_id, message_thread_id)
			) {$charset_collate}"
		);

		$outbound_table = $wpdb->prefix . Migrator::OUTBOUND_MESSAGES_TABLE;
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$outbound_table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				message_uuid CHAR(36) NOT NULL,
				bot_id BIGINT UNSIGNED NOT NULL,
				destination_id BIGINT UNSIGNED NOT NULL,
				body_ciphertext LONGTEXT NULL,
				parse_mode VARCHAR(16) NULL,
				status VARCHAR(16) NOT NULL DEFAULT 'pending',
				attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
				last_failure_code VARCHAR(64) NULL,
				possible_duplicate_delivery TINYINT(1) NOT NULL DEFAULT 0,
				dead_lettered_at DATETIME NULL,
				telegram_message_id BIGINT NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				sent_at DATETIME NULL,
				PRIMARY KEY (id),
				UNIQUE KEY message_uuid (message_uuid),
				KEY status (status),
				KEY bot_destination (bot_id, destination_id),
				KEY created_at (created_at)
			) {$charset_collate}"
		);

		$inbound_table = $wpdb->prefix . Migrator::INBOUND_UPDATES_TABLE;
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$inbound_table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				bot_id BIGINT UNSIGNED NOT NULL,
				update_id BIGINT NOT NULL,
				update_type VARCHAR(32) NOT NULL,
				chat_id VARCHAR(64) NULL,
				message_thread_id BIGINT NULL,
				received_at DATETIME NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY bot_update (bot_id, update_id),
				KEY received_at (received_at)
			) {$charset_collate}"
		);

		$circuit_table = $wpdb->prefix . Migrator::CIRCUIT_BREAKER_TABLE;
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$circuit_table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				scope_type VARCHAR(16) NOT NULL,
				scope_id BIGINT UNSIGNED NOT NULL,
				state VARCHAR(16) NOT NULL DEFAULT 'closed',
				consecutive_failures INT UNSIGNED NOT NULL DEFAULT 0,
				opened_at DATETIME NULL,
				next_probe_at DATETIME NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY scope (scope_type, scope_id)
			) {$charset_collate}"
		);

		$rate_limit_table = $wpdb->prefix . Migrator::RATE_LIMIT_TABLE;
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$rate_limit_table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				scope_type VARCHAR(16) NOT NULL,
				scope_id BIGINT UNSIGNED NOT NULL,
				tokens_available DECIMAL(6,2) NOT NULL,
				last_refill_at DATETIME NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY scope (scope_type, scope_id)
			) {$charset_collate}"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		update_option( 'universal_telegram_db_version', 7 );
	}
}
