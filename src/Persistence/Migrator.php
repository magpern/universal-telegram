<?php
/**
 * Schema migration runner.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Persistence;

/**
 * Runs schema changes as numbered, ordered steps using raw $wpdb->query()
 * data-definition statements, never dbDelta(). The schema-version option
 * advances only immediately after a step's own statements and its own
 * postcondition check both succeed, never partially.
 *
 * Not declared final: tests/integration/Support/FailingMultiStatementMigrator
 * extends this to exercise the generic step-runner's partial-failure
 * behaviour against a synthetic multi-statement step, without touching the
 * one real production step.
 */
class Migrator {

	public const AUDIT_LOG_TABLE                     = 'universal_telegram_audit_log';
	public const BOTS_TABLE                          = 'universal_telegram_bots';
	public const DESTINATIONS_TABLE                  = 'universal_telegram_destinations';
	public const OUTBOUND_MESSAGES_TABLE             = 'universal_telegram_outbound_messages';
	public const INBOUND_UPDATES_TABLE               = 'universal_telegram_inbound_updates';
	public const CIRCUIT_BREAKER_TABLE               = 'universal_telegram_circuit_breaker_state';
	public const RATE_LIMIT_TABLE                    = 'universal_telegram_rate_limit_state';
	public const EVENT_HISTORY_TABLE                 = 'universal_telegram_event_history';
	public const FATAL_ERROR_MARKERS_TABLE           = 'universal_telegram_fatal_error_markers';
	public const NOTIFICATION_RULES_TABLE            = 'universal_telegram_notification_rules';
	public const DISPATCH_LOG_TABLE                  = 'universal_telegram_notification_dispatch_log';
	public const CONVERSATIONS_TABLE                 = 'universal_telegram_conversations';
	public const CONVERSATION_MESSAGES_TABLE         = 'universal_telegram_conversation_messages';
	public const OPERATOR_IDENTITIES_TABLE           = 'universal_telegram_operator_identities';
	public const CONVERSATION_NOTES_TABLE            = 'universal_telegram_conversation_notes';
	public const OPERATOR_AVAILABILITY_TABLE         = 'universal_telegram_operator_availability';
	public const AI_CONFIG_TABLE                     = 'universal_telegram_ai_config';
	public const AI_DRAFTS_TABLE                     = 'universal_telegram_ai_drafts';
	public const VISITOR_DIGEST_COUNTERS_TABLE       = 'universal_telegram_visitor_digest_counters';
	public const VISITOR_DIGEST_STATE_TABLE          = 'universal_telegram_visitor_digest_state';
	public const OPERATIONAL_SUMMARY_RUNS_TABLE      = 'universal_telegram_operational_summary_runs';
	public const INTELLIGENCE_SETTINGS_STATE_TABLE   = 'universal_telegram_intelligence_settings_state';
	public const OPERATIONAL_ALERT_STATE_TABLE       = 'universal_telegram_operational_alert_state';
	public const OPERATIONAL_SUMMARY_AI_DRAFTS_TABLE = 'universal_telegram_operational_summary_ai_drafts';

	private const DB_VERSION_OPTION = 'universal_telegram_db_version';

	/**
	 * Coordinates concurrent migration attempts.
	 *
	 * @var MigrationLock
	 */
	private MigrationLock $lock;

	/**
	 * Constructor.
	 *
	 * @param MigrationLock $lock Coordinates concurrent migration attempts.
	 */
	public function __construct( MigrationLock $lock ) {
		$this->lock = $lock;
	}

	/**
	 * The highest step number this migrator knows how to run. Overridable
	 * by test doubles that append a synthetic step.
	 *
	 * @return int
	 */
	protected function target_version(): int {
		return 29;
	}

	/**
	 * Runs whatever pending steps exist, coordinated by the migration lock.
	 * Never partially advances the schema-version option.
	 *
	 * @throws MigrationFailedException If the lock cannot be acquired while
	 *                                   the schema is still behind, or a
	 *                                   step's statements or its
	 *                                   postcondition check fail.
	 */
	public function maybe_migrate(): void {
		$current_version = (int) get_option( self::DB_VERSION_OPTION, 0 );

		if ( $current_version >= $this->target_version() ) {
			return;
		}

		$handle = $this->lock->acquire();

		if ( null === $handle ) {
			// Another process is migrating (or holds a fresh lock). If the
			// schema is still behind by the time we would otherwise give up,
			// this request's own schema-touching operations must not
			// proceed as though it is available.
			$current_version = (int) get_option( self::DB_VERSION_OPTION, 0 );

			if ( $current_version < $this->target_version() ) {
				throw new MigrationFailedException( MigrationFailureCode::LOCK_UNAVAILABLE );
			}

			return;
		}

		try {
			$this->run_pending_steps( $current_version );
		} finally {
			$this->lock->release( $handle );
		}
	}

	/**
	 * Runs every step between the recorded version and the target version.
	 *
	 * @param int $from_version The schema version already recorded.
	 *
	 * @throws MigrationFailedException If any pending step fails.
	 */
	private function run_pending_steps( int $from_version ): void {
		$target = $this->target_version();

		for ( $number = $from_version + 1; $number <= $target; $number++ ) {
			$this->run_step( $number );
			update_option( self::DB_VERSION_OPTION, $number );
		}
	}

	/**
	 * Runs one step's statements and its postcondition check. Overridable
	 * by test doubles; production code only ever reaches the step 1 case.
	 *
	 * @param int $number The step number to run.
	 *
	 * @throws MigrationFailedException If the step's postcondition check
	 *                                   fails, or the step number is unknown.
	 */
	protected function run_step( int $number ): void {
		$steps = array(
			1  => array( array( $this, 'step_1_create_audit_log_table' ), array( $this, 'verify_step_1' ) ),
			2  => array( array( $this, 'step_2_create_bots_table' ), array( $this, 'verify_step_2' ) ),
			3  => array( array( $this, 'step_3_create_destinations_table' ), array( $this, 'verify_step_3' ) ),
			4  => array( array( $this, 'step_4_create_outbound_messages_table' ), array( $this, 'verify_step_4' ) ),
			5  => array( array( $this, 'step_5_create_inbound_updates_table' ), array( $this, 'verify_step_5' ) ),
			6  => array( array( $this, 'step_6_create_circuit_breaker_table' ), array( $this, 'verify_step_6' ) ),
			7  => array( array( $this, 'step_7_create_rate_limit_table' ), array( $this, 'verify_step_7' ) ),
			8  => array( array( $this, 'step_8_create_events_and_markers_tables' ), array( $this, 'verify_step_8' ) ),
			9  => array( array( $this, 'step_9_create_notification_rules_table' ), array( $this, 'verify_step_9' ) ),
			10 => array( array( $this, 'step_10_create_notification_dispatch_log_table' ), array( $this, 'verify_step_10' ) ),
			11 => array( array( $this, 'step_11_create_conversations_table' ), array( $this, 'verify_step_11' ) ),
			12 => array( array( $this, 'step_12_create_conversation_messages_table' ), array( $this, 'verify_step_12' ) ),
			13 => array( array( $this, 'step_13_add_conversation_idempotency_columns' ), array( $this, 'verify_step_13' ) ),
			14 => array( array( $this, 'step_14_add_claim_lease_columns' ), array( $this, 'verify_step_14' ) ),
			15 => array( array( $this, 'step_15_add_conversation_display_name_column' ), array( $this, 'verify_step_15' ) ),
			16 => array( array( $this, 'step_16_add_conversation_ownership_and_concurrency_index' ), array( $this, 'verify_step_16' ) ),
			17 => array( array( $this, 'step_17_create_operator_workflow_tables' ), array( $this, 'verify_step_17' ) ),
			18 => array( array( $this, 'step_18_add_operator_workflow_columns' ), array( $this, 'verify_step_18' ) ),
			19 => array( array( $this, 'step_19_create_ai_config_table' ), array( $this, 'verify_step_19' ) ),
			20 => array( array( $this, 'step_20_create_ai_drafts_table' ), array( $this, 'verify_step_20' ) ),
			21 => array( array( $this, 'step_21_add_conversation_ai_ack_column' ), array( $this, 'verify_step_21' ) ),
			22 => array( array( $this, 'step_22_make_ai_draft_requester_nullable' ), array( $this, 'verify_step_22' ) ),
			23 => array( array( $this, 'step_23_create_visitor_digest_counters_table' ), array( $this, 'verify_step_23' ) ),
			24 => array( array( $this, 'step_24_create_visitor_digest_state_table' ), array( $this, 'verify_step_24' ) ),
			25 => array( array( $this, 'step_25_create_operational_summary_runs_table' ), array( $this, 'verify_step_25' ) ),
			26 => array( array( $this, 'step_26_create_intelligence_settings_state_table' ), array( $this, 'verify_step_26' ) ),
			27 => array( array( $this, 'step_27_create_operational_alert_state_table' ), array( $this, 'verify_step_27' ) ),
			28 => array( array( $this, 'step_28_create_operational_summary_ai_drafts_table' ), array( $this, 'verify_step_28' ) ),
			29 => array( array( $this, 'step_29_add_conversation_topic_lifecycle_columns' ), array( $this, 'verify_step_29' ) ),
		);

		if ( ! isset( $steps[ $number ] ) ) {
			throw new MigrationFailedException( MigrationFailureCode::STEP_FAILED );
		}

		list( $run, $verify ) = $steps[ $number ];

		$run();

		if ( ! $verify() ) {
			throw new MigrationFailedException( MigrationFailureCode::POSTCONDITION_FAILED );
		}
	}

	/**
	 * Creates the plugin's one database table.
	 */
	private function step_1_create_audit_log_table(): void {
		global $wpdb;

		$table           = $wpdb->prefix . self::AUDIT_LOG_TABLE;
		$charset_collate = $wpdb->get_charset_collate();

		// $wpdb->prepare() cannot parameterize identifiers or an entire DDL
		// statement; the table name and charset/collation clause are a
		// fixed plugin constant and WordPress' own core value, never user
		// input.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$table} (
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
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Re-queries the database's own information schema to confirm the
	 * table genuinely matches what step 1 intended.
	 *
	 * @return bool
	 */
	private function verify_step_1(): bool {
		global $wpdb;

		$table    = $wpdb->prefix . self::AUDIT_LOG_TABLE;
		$expected = array( 'id', 'occurred_at', 'actor_type', 'actor_id', 'action', 'context', 'privacy_classification' );

		$columns = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
				$wpdb->dbname,
				$table
			)
		);

		return array() === array_diff( $expected, $columns );
	}

	/**
	 * Creates the bot profile table. Every row is created with an active
	 * webhook secret already populated (BotProfileRepository's own
	 * responsibility) — this step only establishes the columns, including
	 * the pending-secret columns used solely while a rotation is in
	 * progress (docs/adr/0013).
	 */
	private function step_2_create_bots_table(): void {
		global $wpdb;

		$table           = $wpdb->prefix . self::BOTS_TABLE;
		$charset_collate = $wpdb->get_charset_collate();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$table} (
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
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Verifies the step's postcondition.
	 *
	 * @return bool
	 */
	private function verify_step_2(): bool {
		global $wpdb;

		return $this->table_has_columns(
			$wpdb->prefix . self::BOTS_TABLE,
			array(
				'id',
				'bot_uuid',
				'name',
				'token_ciphertext',
				'webhook_secret_ciphertext',
				'webhook_secret_pending_ciphertext',
				'webhook_secret_pending_since',
				'webhook_registration_state',
				'webhook_last_attempt_at',
				'telegram_bot_id',
				'telegram_username',
				'status',
				'webhook_registered_at',
				'created_at',
				'updated_at',
			)
		);
	}

	/**
	 * Creates the destination table. message_thread_id is stored for every
	 * kind but is validated (repository layer, WP3) to be settable only for
	 * kind = 'supergroup'.
	 */
	private function step_3_create_destinations_table(): void {
		global $wpdb;

		$table           = $wpdb->prefix . self::DESTINATIONS_TABLE;
		$charset_collate = $wpdb->get_charset_collate();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$table} (
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
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Verifies the step's postcondition.
	 *
	 * @return bool
	 */
	private function verify_step_3(): bool {
		global $wpdb;

		return $this->table_has_columns(
			$wpdb->prefix . self::DESTINATIONS_TABLE,
			array( 'id', 'bot_id', 'kind', 'chat_id', 'message_thread_id', 'label', 'enabled', 'created_at' )
		);
	}

	/**
	 * Creates the outbound message table: the only place message content is
	 * ever durably stored, encrypted, outside any queue payload
	 * (docs/adr/0012).
	 */
	private function step_4_create_outbound_messages_table(): void {
		global $wpdb;

		$table           = $wpdb->prefix . self::OUTBOUND_MESSAGES_TABLE;
		$charset_collate = $wpdb->get_charset_collate();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$table} (
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
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Verifies the step's postcondition.
	 *
	 * @return bool
	 */
	private function verify_step_4(): bool {
		global $wpdb;

		return $this->table_has_columns(
			$wpdb->prefix . self::OUTBOUND_MESSAGES_TABLE,
			array(
				'id',
				'message_uuid',
				'bot_id',
				'destination_id',
				'body_ciphertext',
				'parse_mode',
				'status',
				'attempt_count',
				'last_failure_code',
				'possible_duplicate_delivery',
				'dead_lettered_at',
				'telegram_message_id',
				'created_at',
				'updated_at',
				'sent_at',
			)
		);
	}

	/**
	 * Creates the inbound update table: metadata-only receipt, deduplicated
	 * via a UNIQUE(bot_id, update_id) constraint — the replay-protection
	 * mechanism itself (docs/adr/0013).
	 */
	private function step_5_create_inbound_updates_table(): void {
		global $wpdb;

		$table           = $wpdb->prefix . self::INBOUND_UPDATES_TABLE;
		$charset_collate = $wpdb->get_charset_collate();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$table} (
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
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Verifies the step's postcondition.
	 *
	 * @return bool
	 */
	private function verify_step_5(): bool {
		global $wpdb;

		return $this->table_has_columns(
			$wpdb->prefix . self::INBOUND_UPDATES_TABLE,
			array( 'id', 'bot_id', 'update_id', 'update_type', 'chat_id', 'message_thread_id', 'received_at' )
		);
	}

	/**
	 * Creates the circuit-breaker state table, one row per (scope_type,
	 * scope_id) — 'bot' or 'destination' (docs/adr/0014).
	 */
	private function step_6_create_circuit_breaker_table(): void {
		global $wpdb;

		$table           = $wpdb->prefix . self::CIRCUIT_BREAKER_TABLE;
		$charset_collate = $wpdb->get_charset_collate();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$table} (
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
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Verifies the step's postcondition.
	 *
	 * @return bool
	 */
	private function verify_step_6(): bool {
		global $wpdb;

		return $this->table_has_columns(
			$wpdb->prefix . self::CIRCUIT_BREAKER_TABLE,
			array( 'id', 'scope_type', 'scope_id', 'state', 'consecutive_failures', 'opened_at', 'next_probe_at', 'updated_at' )
		);
	}

	/**
	 * Creates the rate-limit token-bucket state table, one row per
	 * (scope_type, scope_id) — 'bot' or 'destination' (docs/adr/0014).
	 */
	private function step_7_create_rate_limit_table(): void {
		global $wpdb;

		$table           = $wpdb->prefix . self::RATE_LIMIT_TABLE;
		$charset_collate = $wpdb->get_charset_collate();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				scope_type VARCHAR(16) NOT NULL,
				scope_id BIGINT UNSIGNED NOT NULL,
				tokens_available DECIMAL(6,2) NOT NULL,
				last_refill_at DATETIME NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY scope (scope_type, scope_id)
			) {$charset_collate}"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Verifies the step's postcondition.
	 *
	 * @return bool
	 */
	private function verify_step_7(): bool {
		global $wpdb;

		return $this->table_has_columns(
			$wpdb->prefix . self::RATE_LIMIT_TABLE,
			array( 'id', 'scope_type', 'scope_id', 'tokens_available', 'last_refill_at' )
		);
	}

	/**
	 * Creates the durable, PUBLIC-only event history projection table
	 * (docs/adr/0017) and the bounded, privacy-safe fatal-error marker
	 * table (M02 plan §5.4, §8.6) in the same step, since both are part of
	 * the Events boundary's own schema.
	 */
	private function step_8_create_events_and_markers_tables(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$history_table = $wpdb->prefix . self::EVENT_HISTORY_TABLE;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$history_table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				event_id CHAR(64) NOT NULL,
				event_type VARCHAR(190) NOT NULL,
				schema_version SMALLINT UNSIGNED NOT NULL,
				occurred_at DATETIME NOT NULL,
				source VARCHAR(32) NOT NULL,
				projected_fields_json TEXT NOT NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY event_id (event_id),
				KEY event_type_occurred_at (event_type, occurred_at)
			) {$charset_collate}"
		);

		$markers_table = $wpdb->prefix . self::FATAL_ERROR_MARKERS_TABLE;
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$markers_table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				error_type VARCHAR(32) NOT NULL,
				location_hash CHAR(64) NOT NULL,
				status VARCHAR(16) NOT NULL DEFAULT 'pending',
				occurred_at DATETIME NOT NULL,
				promoted_at DATETIME NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY error_type_location (error_type, location_hash)
			) {$charset_collate}"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Verifies the step's postcondition.
	 *
	 * @return bool
	 */
	private function verify_step_8(): bool {
		global $wpdb;

		return $this->table_has_columns(
			$wpdb->prefix . self::EVENT_HISTORY_TABLE,
			array( 'id', 'event_id', 'event_type', 'schema_version', 'occurred_at', 'source', 'projected_fields_json', 'created_at' )
		) && $this->table_has_columns(
			$wpdb->prefix . self::FATAL_ERROR_MARKERS_TABLE,
			array( 'id', 'error_type', 'location_hash', 'status', 'occurred_at', 'promoted_at', 'created_at' )
		);
	}

	/**
	 * Creates the notification rule storage table (M02 plan §7.1): a flat,
	 * AND-only condition array per rule, ordered deterministically for
	 * evaluation by (priority ASC, id ASC).
	 */
	private function step_9_create_notification_rules_table(): void {
		global $wpdb;

		$table           = $wpdb->prefix . self::NOTIFICATION_RULES_TABLE;
		$charset_collate = $wpdb->get_charset_collate();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				name VARCHAR(190) NOT NULL,
				event_type VARCHAR(190) NOT NULL,
				schema_version_min SMALLINT UNSIGNED NOT NULL,
				conditions_json TEXT NOT NULL,
				bot_id BIGINT UNSIGNED NOT NULL,
				destination_id BIGINT UNSIGNED NOT NULL,
				template TEXT NOT NULL,
				enabled TINYINT(1) NOT NULL DEFAULT 1,
				priority INT NOT NULL DEFAULT 100,
				cooldown_seconds INT UNSIGNED NOT NULL DEFAULT 0,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY (id),
				KEY event_type_enabled_priority (event_type, enabled, priority, id)
			) {$charset_collate}"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Verifies the step's postcondition.
	 *
	 * @return bool
	 */
	private function verify_step_9(): bool {
		global $wpdb;

		return $this->table_has_columns(
			$wpdb->prefix . self::NOTIFICATION_RULES_TABLE,
			array(
				'id',
				'name',
				'event_type',
				'schema_version_min',
				'conditions_json',
				'bot_id',
				'destination_id',
				'template',
				'enabled',
				'priority',
				'cooldown_seconds',
				'created_at',
				'updated_at',
			)
		);
	}

	/**
	 * Creates the idempotent dispatch-log table (M02 plan §7.5,
	 * docs/adr/0016): the UNIQUE(rule_id, event_id) constraint is the sole
	 * duplicate-prevention mechanism for a rule's handoff decision.
	 */
	private function step_10_create_notification_dispatch_log_table(): void {
		global $wpdb;

		$table           = $wpdb->prefix . self::DISPATCH_LOG_TABLE;
		$charset_collate = $wpdb->get_charset_collate();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				rule_id BIGINT UNSIGNED NOT NULL,
				event_id CHAR(64) NOT NULL,
				outbound_message_uuid CHAR(36) NULL,
				result VARCHAR(32) NOT NULL,
				reason_code VARCHAR(64) NULL,
				dispatched_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY rule_event (rule_id, event_id)
			) {$charset_collate}"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Verifies the step's postcondition.
	 *
	 * @return bool
	 */
	private function verify_step_10(): bool {
		global $wpdb;

		return $this->table_has_columns(
			$wpdb->prefix . self::DISPATCH_LOG_TABLE,
			array( 'id', 'rule_id', 'event_id', 'outbound_message_uuid', 'result', 'reason_code', 'dispatched_at', 'updated_at' )
		);
	}

	/**
	 * Creates the conversations table (docs/adr/0021): one row per visitor
	 * conversation, looked up only by its own unique conversation_uuid — no
	 * bearer token is ever used as, or derived into, a lookup key.
	 * topic_creation_state and telegram_topic_id support the WP3
	 * compare-and-set topic-creation guard; created_at supports the
	 * retention scan (M05 plan §8–§9).
	 */
	private function step_11_create_conversations_table(): void {
		global $wpdb;

		$table           = $wpdb->prefix . self::CONVERSATIONS_TABLE;
		$charset_collate = $wpdb->get_charset_collate();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				conversation_uuid CHAR(36) NOT NULL,
				secret_hash VARCHAR(255) NULL,
				bot_id BIGINT UNSIGNED NOT NULL,
				destination_id BIGINT UNSIGNED NULL,
				chat_profile VARCHAR(64) NULL,
				status VARCHAR(20) NOT NULL DEFAULT 'new',
				assigned_operator_id BIGINT UNSIGNED NULL,
				topic_creation_state VARCHAR(16) NOT NULL DEFAULT 'none',
				telegram_topic_id BIGINT NULL,
				ai_participation_state VARCHAR(16) NOT NULL DEFAULT 'none',
				consent_state VARCHAR(16) NOT NULL DEFAULT 'unknown',
				session_ref VARCHAR(191) NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				resolved_at DATETIME NULL,
				expires_at DATETIME NULL,
				PRIMARY KEY (id),
				UNIQUE KEY conversation_uuid (conversation_uuid),
				KEY status (status),
				KEY telegram_topic_id (telegram_topic_id),
				KEY topic_creation_state (topic_creation_state),
				KEY created_at (created_at)
			) {$charset_collate}"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Verifies the step's postcondition.
	 *
	 * @return bool
	 */
	private function verify_step_11(): bool {
		global $wpdb;

		return $this->table_has_columns(
			$wpdb->prefix . self::CONVERSATIONS_TABLE,
			array(
				'id',
				'conversation_uuid',
				'secret_hash',
				'bot_id',
				'destination_id',
				'chat_profile',
				'status',
				'assigned_operator_id',
				'topic_creation_state',
				'telegram_topic_id',
				'ai_participation_state',
				'consent_state',
				'session_ref',
				'created_at',
				'updated_at',
				'resolved_at',
				'expires_at',
			)
		);
	}

	/**
	 * Creates the conversation messages table (docs/adr/0021): one row per
	 * visitor or operator message, decrypted only per-request for the
	 * authenticated caller (M05 plan §4, §9). conversation_created supports
	 * both the since_id poll cursor and per-conversation retention cleanup.
	 */
	private function step_12_create_conversation_messages_table(): void {
		global $wpdb;

		$table           = $wpdb->prefix . self::CONVERSATION_MESSAGES_TABLE;
		$charset_collate = $wpdb->get_charset_collate();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				conversation_id BIGINT UNSIGNED NOT NULL,
				message_uuid CHAR(36) NOT NULL,
				direction VARCHAR(8) NOT NULL,
				body_ciphertext LONGTEXT NULL,
				outbound_message_uuid CHAR(36) NULL,
				telegram_message_id BIGINT NULL,
				delivery_state VARCHAR(16) NOT NULL DEFAULT 'stored',
				created_at DATETIME NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY message_uuid (message_uuid),
				KEY conversation_created (conversation_id, created_at),
				KEY outbound_message_uuid (outbound_message_uuid)
			) {$charset_collate}"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Verifies the step's postcondition.
	 *
	 * @return bool
	 */
	private function verify_step_12(): bool {
		global $wpdb;

		return $this->table_has_columns(
			$wpdb->prefix . self::CONVERSATION_MESSAGES_TABLE,
			array(
				'id',
				'conversation_id',
				'message_uuid',
				'direction',
				'body_ciphertext',
				'outbound_message_uuid',
				'telegram_message_id',
				'delivery_state',
				'created_at',
			)
		);
	}

	/**
	 * Adds the two idempotency-key columns the M06 chat widget's safe
	 * start/message retry protocol requires (M06 plan §0, ADR-0021
	 * amendment): a nullable, unique `start_idempotency_key` on the
	 * conversations table, and a nullable per-conversation-unique
	 * `idempotency_key` on the conversation messages table. Both columns
	 * are nullable specifically so pre-migration rows need no backfill —
	 * unique indexes permit any number of NULLs, so an upgrade from
	 * db_version 12 is safe with no data loss or blocking rewrite. No new
	 * table; both existing M05 tables are altered in place.
	 *
	 * Unlike every other step's `CREATE TABLE IF NOT EXISTS`, a bare
	 * `ALTER TABLE ... ADD COLUMN` is not itself safely re-runnable — a
	 * second attempt against an already-altered table raises a duplicate-
	 * column error instead of a no-op. This step therefore checks each
	 * table's own information schema first and skips a table already
	 * carrying its column, preserving the same "safely re-runnable"
	 * guarantee every other step provides.
	 */
	private function step_13_add_conversation_idempotency_columns(): void {
		global $wpdb;

		$conversations_table = $wpdb->prefix . self::CONVERSATIONS_TABLE;
		$messages_table      = $wpdb->prefix . self::CONVERSATION_MESSAGES_TABLE;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $this->table_has_columns( $conversations_table, array( 'start_idempotency_key' ) ) ) {
			$wpdb->query(
				"ALTER TABLE {$conversations_table}
					ADD COLUMN start_idempotency_key CHAR(36) NULL,
					ADD UNIQUE KEY start_idempotency_key (start_idempotency_key)"
			);
		}

		if ( ! $this->table_has_columns( $messages_table, array( 'idempotency_key' ) ) ) {
			$wpdb->query(
				"ALTER TABLE {$messages_table}
					ADD COLUMN idempotency_key CHAR(36) NULL,
					ADD UNIQUE KEY conversation_idempotency (conversation_id, idempotency_key)"
			);
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Verifies the step's postcondition.
	 *
	 * @return bool
	 */
	private function verify_step_13(): bool {
		global $wpdb;

		return $this->table_has_columns(
			$wpdb->prefix . self::CONVERSATIONS_TABLE,
			array( 'start_idempotency_key' )
		) && $this->table_has_columns(
			$wpdb->prefix . self::CONVERSATION_MESSAGES_TABLE,
			array( 'idempotency_key' )
		);
	}

	/**
	 * Shared postcondition helper: confirms every expected column exists on
	 * the given table via the database's own information schema.
	 *
	 * @param string             $table    The fully prefixed table name.
	 * @param array<int, string> $expected The expected column names.
	 *
	 * @return bool
	 */
	private function table_has_columns( string $table, array $expected ): bool {
		global $wpdb;

		$columns = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
				$wpdb->dbname,
				$table
			)
		);

		return array() === array_diff( $expected, $columns );
	}

	/**
	 * Adds the two nullable lease columns the claim-protected delivery
	 * protocol requires (M06.2 corrective plan v2, ADR-0023 amendment):
	 * `claim_expires_at` on the outbound messages table and
	 * `topic_claim_expires_at` on the conversations table. Both are
	 * additive and nullable, so no backfill is required on upgrade.
	 *
	 * Like step 13, a bare `ALTER TABLE ... ADD COLUMN` is not itself
	 * safely re-runnable, so this step checks each table's own information
	 * schema first and skips a table already carrying its column.
	 */
	private function step_14_add_claim_lease_columns(): void {
		global $wpdb;

		$outbound_table      = $wpdb->prefix . self::OUTBOUND_MESSAGES_TABLE;
		$conversations_table = $wpdb->prefix . self::CONVERSATIONS_TABLE;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $this->table_has_columns( $outbound_table, array( 'claim_expires_at' ) ) ) {
			$wpdb->query(
				"ALTER TABLE {$outbound_table}
					ADD COLUMN claim_expires_at DATETIME NULL"
			);
		}

		if ( ! $this->table_has_columns( $conversations_table, array( 'topic_claim_expires_at' ) ) ) {
			$wpdb->query(
				"ALTER TABLE {$conversations_table}
					ADD COLUMN topic_claim_expires_at DATETIME NULL"
			);
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Verifies the step's postcondition.
	 *
	 * @return bool
	 */
	private function verify_step_14(): bool {
		global $wpdb;

		return $this->table_has_columns(
			$wpdb->prefix . self::OUTBOUND_MESSAGES_TABLE,
			array( 'claim_expires_at' )
		) && $this->table_has_columns(
			$wpdb->prefix . self::CONVERSATIONS_TABLE,
			array( 'topic_claim_expires_at' )
		);
	}

	/**
	 * Adds the nullable, encrypted visitor display-name column M06.3
	 * introduces (ADR-0024): `display_name_ciphertext` on the conversations
	 * table, storing `CredentialVault::encrypt()` output exactly as message
	 * bodies already do. Additive and nullable, so no backfill is required
	 * on upgrade — a pre-existing conversation simply has no stored name.
	 *
	 * Like steps 13 and 14, a bare `ALTER TABLE ... ADD COLUMN` is not
	 * itself safely re-runnable, so this step checks the table's own
	 * information schema first and skips it if already present.
	 */
	private function step_15_add_conversation_display_name_column(): void {
		global $wpdb;

		$conversations_table = $wpdb->prefix . self::CONVERSATIONS_TABLE;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $this->table_has_columns( $conversations_table, array( 'display_name_ciphertext' ) ) ) {
			$wpdb->query(
				"ALTER TABLE {$conversations_table}
					ADD COLUMN display_name_ciphertext MEDIUMBLOB NULL"
			);
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Verifies the step's postcondition.
	 *
	 * @return bool
	 */
	private function verify_step_15(): bool {
		global $wpdb;

		return $this->table_has_columns(
			$wpdb->prefix . self::CONVERSATIONS_TABLE,
			array( 'display_name_ciphertext' )
		);
	}

	/**
	 * Adds `owner_user_id` (the authenticated WordPress user a conversation
	 * belongs to, nullable — legacy M05-M06.3 rows and any row whose owner
	 * account was later deleted stay NULL forever, never backfilled) and a
	 * generated, indexed `owner_active_slot` column that enforces one active
	 * conversation per (owner_user_id, bot_id) at the database level (M06.3.1,
	 * ADR-0025). "Active" deliberately includes `new` alongside `open`/
	 * `waiting_for_visitor`/`waiting_for_operator`: a conversation is created
	 * in `new` and only reaches `open` once its Telegram topic is created
	 * (ConversationRepository::mark_topic_created()), so excluding `new`
	 * would let two concurrent first-Send requests each insert a `new` row
	 * before either transitions, defeating the guarantee this index exists
	 * to provide. `resolved`/`archived` are excluded, so the slot frees
	 * itself the moment a conversation is resolved, allowing exactly one
	 * fresh active conversation afterward. MySQL/MariaDB unique indexes
	 * never collide on NULL, so ownerless and resolved/archived rows are
	 * always unconstrained by this index.
	 */
	private function step_16_add_conversation_ownership_and_concurrency_index(): void {
		global $wpdb;

		$table = $wpdb->prefix . self::CONVERSATIONS_TABLE;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $this->table_has_columns( $table, array( 'owner_user_id' ) ) ) {
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN owner_user_id BIGINT UNSIGNED NULL" );
		}

		if ( ! $this->table_has_columns( $table, array( 'owner_active_slot' ) ) ) {
			$wpdb->query(
				"ALTER TABLE {$table} ADD COLUMN owner_active_slot VARCHAR(191)
					GENERATED ALWAYS AS (
						CASE WHEN owner_user_id IS NOT NULL
							AND status IN ('new', 'open', 'waiting_for_visitor', 'waiting_for_operator')
						THEN CONCAT(owner_user_id, ':', bot_id)
						ELSE NULL END
					) STORED"
			);
		}

		if ( ! $this->table_has_index( $table, 'owner_active_slot' ) ) {
			$wpdb->query( "ALTER TABLE {$table} ADD UNIQUE KEY owner_active_slot (owner_active_slot)" );
		}

		if ( ! $this->table_has_index( $table, 'owner_user_id' ) ) {
			$wpdb->query( "ALTER TABLE {$table} ADD KEY owner_user_id (owner_user_id)" );
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Verifies the step's postcondition.
	 *
	 * @return bool
	 */
	private function verify_step_16(): bool {
		global $wpdb;

		$table = $wpdb->prefix . self::CONVERSATIONS_TABLE;

		return $this->table_has_columns( $table, array( 'owner_user_id', 'owner_active_slot' ) )
			&& $this->table_has_index( $table, 'owner_active_slot' )
			&& $this->table_has_index( $table, 'owner_user_id' );
	}

	/**
	 * Creates the three new M07 operator-workflow tables (docs/adr/0026):
	 * the manually maintained WordPress-user <-> Telegram numeric-id
	 * identity mapping (the plugin's inbound Telegram operator-
	 * authorization gate), encrypted internal notes, and three-state
	 * operator availability. `conversation_notes.operator_user_id` is
	 * nullable specifically so authorship can be anonymized on operator
	 * account deletion (ADR-0026 decision 12(b2)) without deleting note
	 * content.
	 */
	private function step_17_create_operator_workflow_tables(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$identities_table   = $wpdb->prefix . self::OPERATOR_IDENTITIES_TABLE;
		$notes_table        = $wpdb->prefix . self::CONVERSATION_NOTES_TABLE;
		$availability_table = $wpdb->prefix . self::OPERATOR_AVAILABILITY_TABLE;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$identities_table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				wp_user_id BIGINT UNSIGNED NOT NULL,
				telegram_user_id BIGINT UNSIGNED NOT NULL,
				telegram_username VARCHAR(255) NULL,
				created_at DATETIME NOT NULL,
				created_by BIGINT UNSIGNED NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY wp_user_id (wp_user_id),
				UNIQUE KEY telegram_user_id (telegram_user_id)
			) {$charset_collate}"
		);

		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$notes_table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				conversation_id BIGINT UNSIGNED NOT NULL,
				operator_user_id BIGINT UNSIGNED NULL,
				body_ciphertext LONGTEXT NOT NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY (id),
				KEY conversation_created (conversation_id, created_at)
			) {$charset_collate}"
		);

		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$availability_table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				operator_user_id BIGINT UNSIGNED NOT NULL,
				state VARCHAR(16) NOT NULL DEFAULT 'offline',
				updated_at DATETIME NOT NULL,
				updated_by BIGINT UNSIGNED NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY operator_user_id (operator_user_id)
			) {$charset_collate}"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Verifies the step's postcondition.
	 *
	 * @return bool
	 */
	private function verify_step_17(): bool {
		global $wpdb;

		return $this->table_has_columns(
			$wpdb->prefix . self::OPERATOR_IDENTITIES_TABLE,
			array( 'id', 'wp_user_id', 'telegram_user_id', 'telegram_username', 'created_at', 'created_by' )
		) && $this->table_has_columns(
			$wpdb->prefix . self::CONVERSATION_NOTES_TABLE,
			array( 'id', 'conversation_id', 'operator_user_id', 'body_ciphertext', 'created_at' )
		) && $this->table_has_columns(
			$wpdb->prefix . self::OPERATOR_AVAILABILITY_TABLE,
			array( 'id', 'operator_user_id', 'state', 'updated_at', 'updated_by' )
		);
	}

	/**
	 * Adds the M07 assignment-unread column (`assignee_last_seen_message_id`
	 * on conversations) and the M07 inbound-attribution column
	 * (`telegram_sender_user_id` on conversation messages), the latter
	 * indexed since WebhookController joins on it to attribute an accepted
	 * operator reply to a mapped WordPress display name, but never renders,
	 * URL-exposes, or search-filters the raw value itself (ADR-0026
	 * decisions 2-3, SENSITIVE classification).
	 */
	private function step_18_add_operator_workflow_columns(): void {
		global $wpdb;

		$conversations_table = $wpdb->prefix . self::CONVERSATIONS_TABLE;
		$messages_table      = $wpdb->prefix . self::CONVERSATION_MESSAGES_TABLE;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $this->table_has_columns( $conversations_table, array( 'assignee_last_seen_message_id' ) ) ) {
			$wpdb->query( "ALTER TABLE {$conversations_table} ADD COLUMN assignee_last_seen_message_id BIGINT UNSIGNED NULL" );
		}

		if ( ! $this->table_has_columns( $messages_table, array( 'telegram_sender_user_id' ) ) ) {
			$wpdb->query( "ALTER TABLE {$messages_table} ADD COLUMN telegram_sender_user_id BIGINT UNSIGNED NULL" );
		}

		if ( ! $this->table_has_index( $messages_table, 'telegram_sender_user_id' ) ) {
			$wpdb->query( "ALTER TABLE {$messages_table} ADD KEY telegram_sender_user_id (telegram_sender_user_id)" );
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Verifies the step's postcondition.
	 *
	 * @return bool
	 */
	private function verify_step_18(): bool {
		global $wpdb;

		$conversations_table = $wpdb->prefix . self::CONVERSATIONS_TABLE;
		$messages_table      = $wpdb->prefix . self::CONVERSATION_MESSAGES_TABLE;

		return $this->table_has_columns( $conversations_table, array( 'assignee_last_seen_message_id' ) )
			&& $this->table_has_columns( $messages_table, array( 'telegram_sender_user_id' ) )
			&& $this->table_has_index( $messages_table, 'telegram_sender_user_id' );
	}

	/**
	 * Whether a given table has an index (of any kind) with the given name.
	 *
	 * @param string $table The fully-prefixed table name.
	 * @param string $index_name The index name.
	 *
	 * @return bool
	 */
	private function table_has_index( string $table, string $index_name ): bool {
		global $wpdb;

		$found = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = %s',
				$wpdb->dbname,
				$table,
				$index_name
			)
		);

		return null !== $found && (int) $found > 0;
	}

	/**
	 * Creates the M09 AI provider configuration singleton table and seeds
	 * its one row (`id=1`, `enabled=0`). The row must exist unconditionally
	 * before any admin ever saves configuration, because
	 * AI\Draft\AIDraftGenerationHandler locks it (`SELECT ... FOR UPDATE`)
	 * as the site-wide generation-concurrency admission mutex (docs/adr/0028
	 * decision 5) — a mutex cannot lock a row that might not exist yet.
	 */
	private function step_19_create_ai_config_table(): void {
		global $wpdb;

		$table           = $wpdb->prefix . self::AI_CONFIG_TABLE;
		$charset_collate = $wpdb->get_charset_collate();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$table} (
				id BIGINT UNSIGNED NOT NULL,
				provider VARCHAR(32) NOT NULL,
				model VARCHAR(191) NOT NULL,
				api_key_ciphertext LONGTEXT NULL,
				enabled TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
				ack_policy_version VARCHAR(32) NOT NULL,
				ack_text TEXT NOT NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY (id)
			) {$charset_collate}"
		);

		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (id, provider, model, api_key_ciphertext, enabled, ack_policy_version, ack_text, created_at, updated_at)
					VALUES (1, %s, '', NULL, 0, %s, %s, %s, %s)",
				'openai',
				'v1',
				'Your messages may be reviewed with AI assistance to help support staff respond.',
				current_time( 'mysql', true ),
				current_time( 'mysql', true )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Verifies the step's postcondition: the table exists with the expected
	 * columns and its singleton row is present.
	 *
	 * @return bool
	 */
	private function verify_step_19(): bool {
		global $wpdb;

		$table = $wpdb->prefix . self::AI_CONFIG_TABLE;

		if ( ! $this->table_has_columns(
			$table,
			array( 'id', 'provider', 'model', 'api_key_ciphertext', 'enabled', 'ack_policy_version', 'ack_text', 'created_at', 'updated_at' )
		) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row_exists = $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE id = 1" );

		return null !== $row_exists && 1 === (int) $row_exists;
	}

	/**
	 * Creates the M09 AI draft persistence table, including the
	 * generation-lease/claim columns (`lease_token`,
	 * `generation_lease_expires_at`, `claimed_at`, `attempt_count`) from
	 * initial creation rather than a later alteration (docs/adr/0028
	 * decisions 4 and 5) — these back the compare-and-set claim protocol
	 * AI\Draft\AIDraftGenerationHandler and AI\Draft\AiDraftLeaseSweep use
	 * to recover a crashed worker's row without ever double-writing a
	 * stale outcome.
	 */
	private function step_20_create_ai_drafts_table(): void {
		global $wpdb;

		$table           = $wpdb->prefix . self::AI_DRAFTS_TABLE;
		$charset_collate = $wpdb->get_charset_collate();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				draft_uuid CHAR(36) NOT NULL,
				conversation_id BIGINT UNSIGNED NOT NULL,
				status VARCHAR(16) NOT NULL,
				provider VARCHAR(32) NOT NULL,
				model VARCHAR(191) NOT NULL,
				prompt_policy_version VARCHAR(32) NOT NULL,
				source_ids_json TEXT NULL,
				context_fingerprint CHAR(64) NULL,
				body_ciphertext LONGTEXT NULL,
				failure_class VARCHAR(32) NULL,
				requested_by_user_id BIGINT UNSIGNED NOT NULL,
				reviewed_by_user_id BIGINT UNSIGNED NULL,
				job_reference VARCHAR(64) NULL,
				lease_token CHAR(36) NULL,
				generation_lease_expires_at DATETIME NULL,
				claimed_at DATETIME NULL,
				attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
				created_at DATETIME NOT NULL,
				generated_at DATETIME NULL,
				reviewed_at DATETIME NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY uq_ai_drafts_uuid (draft_uuid),
				KEY idx_ai_drafts_conversation (conversation_id),
				KEY idx_ai_drafts_status (status),
				KEY idx_ai_drafts_conv_status (conversation_id, status),
				KEY idx_ai_drafts_lease (status, generation_lease_expires_at)
			) {$charset_collate}"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Verifies the step's postcondition.
	 *
	 * @return bool
	 */
	private function verify_step_20(): bool {
		global $wpdb;

		return $this->table_has_columns(
			$wpdb->prefix . self::AI_DRAFTS_TABLE,
			array(
				'id',
				'draft_uuid',
				'conversation_id',
				'status',
				'provider',
				'model',
				'prompt_policy_version',
				'source_ids_json',
				'context_fingerprint',
				'body_ciphertext',
				'failure_class',
				'requested_by_user_id',
				'reviewed_by_user_id',
				'job_reference',
				'lease_token',
				'generation_lease_expires_at',
				'claimed_at',
				'attempt_count',
				'created_at',
				'generated_at',
				'reviewed_at',
				'updated_at',
			)
		);
	}

	/**
	 * Adds the nullable per-conversation AI-acknowledgement column
	 * (docs/adr/0028 decision 1). `NULL` means ineligible; a non-null value
	 * must equal the AI config row's current `ack_policy_version` exactly
	 * for the conversation to be draft-eligible. This is the only write
	 * path to the column — set once at conversation-creation time, never
	 * backfilled or upgraded later.
	 */
	private function step_21_add_conversation_ai_ack_column(): void {
		global $wpdb;

		$table = $wpdb->prefix . self::CONVERSATIONS_TABLE;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $this->table_has_columns( $table, array( 'ai_ack_policy_version' ) ) ) {
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN ai_ack_policy_version VARCHAR(32) NULL" );
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Verifies the step's postcondition.
	 *
	 * @return bool
	 */
	private function verify_step_21(): bool {
		global $wpdb;

		return $this->table_has_columns(
			$wpdb->prefix . self::CONVERSATIONS_TABLE,
			array( 'ai_ack_policy_version' )
		);
	}

	/**
	 * Makes `requested_by_user_id` nullable, so it can be anonymized on
	 * operator account deletion (docs/adr/0028 §4 retention table) without
	 * deleting the draft row itself — mirroring
	 * ConversationNote.operator_user_id's identical, already-nullable
	 * anonymization precedent (ADR-0026 decision 12b). Column was created
	 * NOT NULL in step 20; this widens it, a safe operation with no
	 * existing NULL values to migrate.
	 */
	private function step_22_make_ai_draft_requester_nullable(): void {
		global $wpdb;

		$table = $wpdb->prefix . self::AI_DRAFTS_TABLE;

		if ( 'YES' !== $this->column_is_nullable( $table, 'requested_by_user_id' ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$table} MODIFY COLUMN requested_by_user_id BIGINT UNSIGNED NULL" );
		}
	}

	/**
	 * Verifies the step's postcondition.
	 *
	 * @return bool
	 */
	private function verify_step_22(): bool {
		global $wpdb;

		return 'YES' === $this->column_is_nullable( $wpdb->prefix . self::AI_DRAFTS_TABLE, 'requested_by_user_id' );
	}

	/**
	 * Creates the visitor-digest aggregation-window counters table (M11A,
	 * docs/plans/m11a-visitor-activity-digests-plan-v1.md §5): one row per
	 * (window, category, page_type) bucket, incremented synchronously by
	 * Automations\Digest\VisitorDigestCounterRepository from
	 * Events\EventDispatcher::handle() while a digest window is open. Never
	 * one row per event.
	 */
	private function step_23_create_visitor_digest_counters_table(): void {
		global $wpdb;

		$table           = $wpdb->prefix . self::VISITOR_DIGEST_COUNTERS_TABLE;
		$charset_collate = $wpdb->get_charset_collate();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				window_started_at DATETIME NOT NULL,
				category VARCHAR(32) NOT NULL,
				page_type VARCHAR(16) NULL,
				event_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
				last_event_at DATETIME NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY window_category_page (window_started_at, category, page_type),
				KEY idx_window_started_at (window_started_at)
			) {$charset_collate}"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Verifies the step's postcondition.
	 *
	 * @return bool
	 */
	private function verify_step_23(): bool {
		global $wpdb;

		return $this->table_has_columns(
			$wpdb->prefix . self::VISITOR_DIGEST_COUNTERS_TABLE,
			array( 'id', 'window_started_at', 'category', 'page_type', 'event_count', 'last_event_at' )
		);
	}

	/**
	 * Creates the visitor-digest singleton state/checkpoint row (M11A §5):
	 * one seeded row (id=1), the same "singleton row as mutex/checkpoint"
	 * pattern step_19_create_ai_config_table already established for
	 * universal_telegram_ai_config (docs/adr/0028 decision 5). Locked
	 * (SELECT ... FOR UPDATE) by Automations\Digest\VisitorDigestSweep as
	 * its sole admission mutex.
	 */
	private function step_24_create_visitor_digest_state_table(): void {
		global $wpdb;

		$table           = $wpdb->prefix . self::VISITOR_DIGEST_STATE_TABLE;
		$charset_collate = $wpdb->get_charset_collate();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$table} (
				id TINYINT UNSIGNED NOT NULL,
				window_started_at DATETIME NULL,
				last_digest_sent_at DATETIME NULL,
				last_digest_status VARCHAR(32) NULL,
				claim_token CHAR(36) NULL,
				claim_expires_at DATETIME NULL,
				PRIMARY KEY (id)
			) {$charset_collate}"
		);

		$wpdb->query( "INSERT IGNORE INTO {$table} (id) VALUES (1)" );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Verifies the step's postcondition.
	 *
	 * @return bool
	 */
	private function verify_step_24(): bool {
		global $wpdb;

		$table = $wpdb->prefix . self::VISITOR_DIGEST_STATE_TABLE;

		if ( ! $this->table_has_columns(
			$table,
			array( 'id', 'window_started_at', 'last_digest_sent_at', 'last_digest_status', 'claim_token', 'claim_expires_at' )
		) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return null !== $wpdb->get_var( "SELECT id FROM {$table} WHERE id = 1" );
	}

	/**
	 * Creates the operational-summary daily aggregate table (M11B plan §4,
	 * step 25): one row per UTC calendar day, keyed by summary_date's own
	 * UNIQUE constraint — the sole row-creation-idempotency mechanism a
	 * crash or retried sweep tick relies on (never an application-level
	 * lock alone).
	 */
	private function step_25_create_operational_summary_runs_table(): void {
		global $wpdb;

		$table           = $wpdb->prefix . self::OPERATIONAL_SUMMARY_RUNS_TABLE;
		$charset_collate = $wpdb->get_charset_collate();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				summary_date DATE NOT NULL,
				window_started_at DATETIME NOT NULL,
				window_ended_at DATETIME NOT NULL,
				orders_created INT UNSIGNED NOT NULL DEFAULT 0,
				payments_completed INT UNSIGNED NOT NULL DEFAULT 0,
				orders_failed INT UNSIGNED NOT NULL DEFAULT 0,
				orders_cancelled INT UNSIGNED NOT NULL DEFAULT 0,
				checkout_failures INT UNSIGNED NOT NULL DEFAULT 0,
				js_error_runtime INT UNSIGNED NOT NULL DEFAULT 0,
				js_error_promise INT UNSIGNED NOT NULL DEFAULT 0,
				js_error_resource INT UNSIGNED NOT NULL DEFAULT 0,
				funnel_product_views INT UNSIGNED NOT NULL DEFAULT 0,
				funnel_cart_intents INT UNSIGNED NOT NULL DEFAULT 0,
				funnel_checkout_starts INT UNSIGNED NOT NULL DEFAULT 0,
				funnel_orders_created INT UNSIGNED NOT NULL DEFAULT 0,
				woocommerce_active_at_run TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
				sent_at DATETIME NULL,
				send_status VARCHAR(32) NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY uq_summary_date (summary_date)
			) {$charset_collate}"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Verifies the step's postcondition.
	 *
	 * @return bool
	 */
	private function verify_step_25(): bool {
		global $wpdb;

		return $this->table_has_columns(
			$wpdb->prefix . self::OPERATIONAL_SUMMARY_RUNS_TABLE,
			array( 'id', 'summary_date', 'window_started_at', 'window_ended_at', 'orders_created', 'payments_completed', 'orders_failed', 'orders_cancelled', 'checkout_failures', 'sent_at', 'send_status' )
		);
	}

	/**
	 * Creates the Intelligence sweep's singleton claim-lease mutex row
	 * (M11B plan §4, step 26) — the same "singleton row as mutex/checkpoint"
	 * pattern step_24_create_visitor_digest_state_table already established
	 * for the visitor digest. Ordered ahead of the alert-state table (step
	 * 27) so the sweep's mutex exists before either the summary or the
	 * alerts it also evaluates ever run.
	 */
	private function step_26_create_intelligence_settings_state_table(): void {
		global $wpdb;

		$table           = $wpdb->prefix . self::INTELLIGENCE_SETTINGS_STATE_TABLE;
		$charset_collate = $wpdb->get_charset_collate();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$table} (
				id TINYINT UNSIGNED NOT NULL,
				claim_token CHAR(36) NULL,
				claim_expires_at DATETIME NULL,
				PRIMARY KEY (id)
			) {$charset_collate}"
		);

		$wpdb->query( "INSERT IGNORE INTO {$table} (id) VALUES (1)" );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Verifies the step's postcondition.
	 *
	 * @return bool
	 */
	private function verify_step_26(): bool {
		global $wpdb;

		$table = $wpdb->prefix . self::INTELLIGENCE_SETTINGS_STATE_TABLE;

		if ( ! $this->table_has_columns( $table, array( 'id', 'claim_token', 'claim_expires_at' ) ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return null !== $wpdb->get_var( "SELECT id FROM {$table} WHERE id = 1" );
	}

	/**
	 * Creates the fixed three-row threshold-alert cooldown/checkpoint table
	 * (M11B plan §2.2/§4, step 27), seeded with the three fixed alert-type
	 * rows during migration — the same "singleton-row(s) as checkpoint"
	 * pattern step_24_create_visitor_digest_state_table already established,
	 * here with a fixed cardinality of three rather than one.
	 */
	private function step_27_create_operational_alert_state_table(): void {
		global $wpdb;

		$table           = $wpdb->prefix . self::OPERATIONAL_ALERT_STATE_TABLE;
		$charset_collate = $wpdb->get_charset_collate();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$table} (
				alert_type VARCHAR(32) NOT NULL,
				last_fired_at DATETIME NULL,
				last_evaluated_at DATETIME NULL,
				PRIMARY KEY (alert_type)
			) {$charset_collate}"
		);

		foreach ( array( 'checkout_failure_count', 'order_failure_spike', 'js_error_spike' ) as $alert_type ) {
			$wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$table} (alert_type) VALUES (%s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$alert_type
				)
			);
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Verifies the step's postcondition.
	 *
	 * @return bool
	 */
	private function verify_step_27(): bool {
		global $wpdb;

		$table = $wpdb->prefix . self::OPERATIONAL_ALERT_STATE_TABLE;

		if ( ! $this->table_has_columns( $table, array( 'alert_type', 'last_fired_at', 'last_evaluated_at' ) ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return 3 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * Creates the operational-summary AI-draft table (M11B plan §2.6/§3/§4,
	 * step 28). UNIQUE(summary_run_id) is the entire per-summary
	 * idempotency mechanism — a database constraint, not an
	 * application-level row lock: at most one draft row can ever exist per
	 * summary. requested_by_user_id/reviewed_by_user_id are nullable from
	 * creation (matching universal_telegram_ai_drafts' own nullable-
	 * widening precedent, applied here from the start rather than as a
	 * later corrective migration) so account-deletion anonymization can
	 * null them without ever deleting the row.
	 */
	private function step_28_create_operational_summary_ai_drafts_table(): void {
		global $wpdb;

		$table           = $wpdb->prefix . self::OPERATIONAL_SUMMARY_AI_DRAFTS_TABLE;
		$charset_collate = $wpdb->get_charset_collate();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				summary_run_id BIGINT UNSIGNED NOT NULL,
				draft_uuid CHAR(36) NOT NULL,
				status VARCHAR(16) NOT NULL,
				provider VARCHAR(32) NOT NULL,
				model VARCHAR(191) NOT NULL,
				prompt_policy_version VARCHAR(32) NOT NULL,
				body_ciphertext LONGTEXT NULL,
				failure_class VARCHAR(32) NULL,
				requested_by_user_id BIGINT UNSIGNED NULL,
				reviewed_by_user_id BIGINT UNSIGNED NULL,
				lease_token CHAR(36) NULL,
				generation_lease_expires_at DATETIME NULL,
				attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
				created_at DATETIME NOT NULL,
				generated_at DATETIME NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY uq_summary_run (summary_run_id),
				UNIQUE KEY uq_draft_uuid (draft_uuid),
				KEY idx_status (status),
				KEY idx_lease (status, generation_lease_expires_at)
			) {$charset_collate}"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Verifies the step's postcondition.
	 *
	 * @return bool
	 */
	private function verify_step_28(): bool {
		global $wpdb;

		return $this->table_has_columns(
			$wpdb->prefix . self::OPERATIONAL_SUMMARY_AI_DRAFTS_TABLE,
			array( 'id', 'summary_run_id', 'draft_uuid', 'status', 'provider', 'model', 'body_ciphertext', 'requested_by_user_id', 'reviewed_by_user_id', 'lease_token', 'generation_lease_expires_at', 'attempt_count' )
		);
	}

	/**
	 * Adds topic-lifecycle columns and exclusive destination_id ownership
	 * (M07.1, docs/adr/0031): topic_lifecycle_state/code, delete claim
	 * lease, backfill active for created topics, null duplicate
	 * destination_id references, then UNIQUE(destination_id).
	 */
	private function step_29_add_conversation_topic_lifecycle_columns(): void {
		global $wpdb;

		$table        = $wpdb->prefix . self::CONVERSATIONS_TABLE;
		$destinations = $wpdb->prefix . self::DESTINATIONS_TABLE;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// SHOW COLUMNS (this connection) — not INFORMATION_SCHEMA — so a
		// DROP TABLE + recreate in the same PHPUnit process cannot leave a
		// stale "column exists" view that skips the ADD and breaks DML.
		if ( empty( $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'topic_lifecycle_state'" ) ) ) {
			$wpdb->query(
				"ALTER TABLE {$table}
					ADD COLUMN topic_lifecycle_state VARCHAR(16) NOT NULL DEFAULT 'none'"
			);
		}

		if ( empty( $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'topic_lifecycle_code'" ) ) ) {
			$wpdb->query(
				"ALTER TABLE {$table}
					ADD COLUMN topic_lifecycle_code VARCHAR(64) NULL"
			);
		}

		if ( empty( $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'topic_delete_claim_expires_at'" ) ) ) {
			$wpdb->query(
				"ALTER TABLE {$table}
					ADD COLUMN topic_delete_claim_expires_at DATETIME NULL"
			);
		}

		// SHOW INDEX (this connection) — not INFORMATION_SCHEMA — for the
		// same DROP TABLE + recreate reason as the column checks above.
		if ( empty( $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Key_name = 'topic_lifecycle_state'" ) ) ) {
			$wpdb->query( "ALTER TABLE {$table} ADD KEY topic_lifecycle_state (topic_lifecycle_state)" );
		}

		$wpdb->query(
			"UPDATE {$table}
				SET topic_lifecycle_state = 'active'
				WHERE topic_creation_state = 'created'
				  AND topic_lifecycle_state = 'none'"
		);

		// Exclusive destination ownership: keep one owner per destination_id
		// (prefer created topic matching the destination's thread; else lowest id),
		// then UNIQUE. Extras become remote-ineligible.
		$duplicates = $wpdb->get_col(
			"SELECT destination_id FROM {$table}
				WHERE destination_id IS NOT NULL
				GROUP BY destination_id
				HAVING COUNT(*) > 1"
		);

		if ( is_array( $duplicates ) ) {
			foreach ( $duplicates as $destination_id ) {
				$destination_id = (int) $destination_id;
				$owner_id       = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT c.id FROM {$table} c
							INNER JOIN {$destinations} d ON d.id = c.destination_id
							WHERE c.destination_id = %d
							  AND c.topic_creation_state = 'created'
							  AND c.telegram_topic_id IS NOT NULL
							  AND c.telegram_topic_id = d.message_thread_id
							ORDER BY c.id ASC
							LIMIT 1",
						$destination_id
					)
				);

				if ( null === $owner_id ) {
					$owner_id = $wpdb->get_var(
						$wpdb->prepare(
							"SELECT id FROM {$table} WHERE destination_id = %d ORDER BY id ASC LIMIT 1",
							$destination_id
						)
					);
				}

				if ( null === $owner_id ) {
					continue;
				}

				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$table}
							SET destination_id = NULL
							WHERE destination_id = %d AND id <> %d",
						$destination_id,
						(int) $owner_id
					)
				);
			}
		}

		if ( empty( $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Key_name = 'destination_id' AND Non_unique = 0" ) ) ) {
			$wpdb->query( "ALTER TABLE {$table} ADD UNIQUE KEY destination_id (destination_id)" );
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Verifies the step's postcondition.
	 *
	 * @return bool
	 */
	private function verify_step_29(): bool {
		global $wpdb;

		$table = $wpdb->prefix . self::CONVERSATIONS_TABLE;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$has_state     = ! empty( $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'topic_lifecycle_state'" ) );
		$has_code      = ! empty( $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'topic_lifecycle_code'" ) );
		$has_claim     = ! empty( $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'topic_delete_claim_expires_at'" ) );
		$has_state_idx = ! empty( $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Key_name = 'topic_lifecycle_state'" ) );
		$has_dest_uq   = ! empty( $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Key_name = 'destination_id' AND Non_unique = 0" ) );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $has_state && $has_code && $has_claim && $has_state_idx && $has_dest_uq;
	}

	/**
	 * Whether a column currently permits NULL.
	 *
	 * @param string $table  The fully-prefixed table name.
	 * @param string $column The column name.
	 *
	 * @return string 'YES' or 'NO' (INFORMATION_SCHEMA's own literal values), or '' if not found.
	 */
	private function column_is_nullable( string $table, string $column ): string {
		global $wpdb;

		$value = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s',
				$wpdb->dbname,
				$table,
				$column
			)
		);

		return null === $value ? '' : (string) $value;
	}
}
