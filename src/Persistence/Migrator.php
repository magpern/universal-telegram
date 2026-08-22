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

	public const AUDIT_LOG_TABLE             = 'universal_telegram_audit_log';
	public const BOTS_TABLE                  = 'universal_telegram_bots';
	public const DESTINATIONS_TABLE          = 'universal_telegram_destinations';
	public const OUTBOUND_MESSAGES_TABLE     = 'universal_telegram_outbound_messages';
	public const INBOUND_UPDATES_TABLE       = 'universal_telegram_inbound_updates';
	public const CIRCUIT_BREAKER_TABLE       = 'universal_telegram_circuit_breaker_state';
	public const RATE_LIMIT_TABLE            = 'universal_telegram_rate_limit_state';
	public const EVENT_HISTORY_TABLE         = 'universal_telegram_event_history';
	public const FATAL_ERROR_MARKERS_TABLE   = 'universal_telegram_fatal_error_markers';
	public const NOTIFICATION_RULES_TABLE    = 'universal_telegram_notification_rules';
	public const DISPATCH_LOG_TABLE          = 'universal_telegram_notification_dispatch_log';
	public const CONVERSATIONS_TABLE         = 'universal_telegram_conversations';
	public const CONVERSATION_MESSAGES_TABLE = 'universal_telegram_conversation_messages';
	public const OPERATOR_IDENTITIES_TABLE   = 'universal_telegram_operator_identities';
	public const CONVERSATION_NOTES_TABLE    = 'universal_telegram_conversation_notes';
	public const OPERATOR_AVAILABILITY_TABLE = 'universal_telegram_operator_availability';

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
		return 18;
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
}
