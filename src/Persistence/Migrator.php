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
	public const SUPPORT_CHAT_BINDINGS_TABLE         = 'universal_telegram_support_chat_bindings';
	public const SUPPORT_CHAT_DELIVERY_KEYS_TABLE    = 'universal_telegram_support_chat_delivery_keys';
	public const SUPPORT_CHAT_PEERS_TABLE            = 'universal_telegram_support_chat_peers';
	public const CONTRACT_NONCES_TABLE               = 'universal_telegram_support_chat_contract_nonces';
	public const QUIESCENCE_STATE_TABLE              = 'universal_telegram_quiescence_state';
	public const QUIESCENCE_TRANSITIONS_TABLE        = 'universal_telegram_quiescence_transitions';
	public const QUIESCENCE_DEFERRED_UPDATES_TABLE   = 'universal_telegram_quiescence_deferred_updates';
	public const CUTOVER_RUNS_TABLE                  = 'universal_telegram_cutover_runs';
	public const CUTOVER_TRANSITIONS_TABLE           = 'universal_telegram_cutover_transitions';
	public const CUTOVER_ACTIVATION_AUDIT_TABLE      = 'universal_telegram_cutover_activation_audit';

	/**
	 * The retained Telegram-user -> WordPress-operator identity mapping
	 * (ADR-0044 §4). Created by step 37, populated on upgrade from the
	 * obsolete OPERATOR_IDENTITIES_TABLE via an exact bijection check.
	 */
	public const OPERATOR_IDENTITY_MAP_TABLE = 'universal_telegram_operator_identity_map';

	/**
	 * Set (to a timestamp) by step 37 on an upgrade that still has obsolete
	 * legacy tables, and cleared by `legacy-chat purge`. Its presence is how
	 * an upgraded-but-not-yet-purged install is told apart from a clean one.
	 */
	public const LEGACY_CHAT_RETIRED_OPTION = 'universal_telegram_legacy_chat_retired_at';

	/**
	 * Obsolete tables retired by ADR-0044 (former steps 11-29, 33, 35, 36).
	 * Names only — the migrator no longer creates them; `legacy-chat purge`
	 * and uninstall use this manifest to recognise and remove them.
	 *
	 * @var array<int, string>
	 */
	public const LEGACY_TABLES = array(
		self::CONVERSATIONS_TABLE,
		self::CONVERSATION_MESSAGES_TABLE,
		self::CONVERSATION_NOTES_TABLE,
		self::OPERATOR_IDENTITIES_TABLE,
		self::OPERATOR_AVAILABILITY_TABLE,
		self::AI_CONFIG_TABLE,
		self::AI_DRAFTS_TABLE,
		self::VISITOR_DIGEST_COUNTERS_TABLE,
		self::VISITOR_DIGEST_STATE_TABLE,
		self::OPERATIONAL_SUMMARY_RUNS_TABLE,
		self::INTELLIGENCE_SETTINGS_STATE_TABLE,
		self::OPERATIONAL_ALERT_STATE_TABLE,
		self::OPERATIONAL_SUMMARY_AI_DRAFTS_TABLE,
		self::QUIESCENCE_STATE_TABLE,
		self::QUIESCENCE_TRANSITIONS_TABLE,
		self::QUIESCENCE_DEFERRED_UPDATES_TABLE,
		self::CUTOVER_RUNS_TABLE,
		self::CUTOVER_TRANSITIONS_TABLE,
		self::CUTOVER_ACTIVATION_AUDIT_TABLE,
	);

	/**
	 * Obsolete option keys retired by ADR-0044. Removed by `legacy-chat
	 * purge` and by uninstall (only when data removal is authorized).
	 *
	 * @var array<int, string>
	 */
	public const LEGACY_OPTIONS = array(
		'universal_telegram_ai_settings',
		'universal_telegram_intelligence_settings',
		'universal_telegram_visitor_tracking_secret',
		'universal_telegram_conversation_rate_limit_secret',
		'universal_telegram_visitor_rate_limit_secret',
	);

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
		return 37;
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
		// Steps 11-29, 33, 35, 36 built the legacy website-chat and SC-M03
		// migration/cutover schema. ADR-0044 retired them: their methods are
		// gone, the step slots are inert no-ops so the version line stays
		// monotonic and any install already at those versions is unaffected,
		// and the obsolete tables are recognised for removal only via the
		// LEGACY_TABLES manifest + `wp universal-telegram legacy-chat purge`.
		$retired = array( array( $this, 'step_retired' ), array( $this, 'verify_retired' ) );

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
			11 => $retired,
			12 => $retired,
			13 => $retired,
			14 => $retired,
			15 => $retired,
			16 => $retired,
			17 => $retired,
			18 => $retired,
			19 => $retired,
			20 => $retired,
			21 => $retired,
			22 => $retired,
			23 => $retired,
			24 => $retired,
			25 => $retired,
			26 => $retired,
			27 => $retired,
			28 => $retired,
			29 => $retired,
			30 => array( array( $this, 'step_30_add_notification_rule_match_mode_column' ), array( $this, 'verify_step_30' ) ),
			31 => array( array( $this, 'step_31_create_support_chat_adapter_tables' ), array( $this, 'verify_step_31' ) ),
			32 => array( array( $this, 'step_32_create_support_chat_contract_auth_tables' ), array( $this, 'verify_step_32' ) ),
			33 => array( array( $this, 'step_33_create_quiescence_tables' ), array( $this, 'verify_step_33' ) ),
			34 => array( array( $this, 'step_34_add_prepared_binding_status' ), array( $this, 'verify_step_34' ) ),
			35 => $retired,
			36 => $retired,
			37 => array( array( $this, 'step_37_retire_legacy_chat' ), array( $this, 'verify_step_37' ) ),
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
	 * Inert slot for a step retired by ADR-0044. Creates nothing.
	 */
	private function step_retired(): void {}

	/**
	 * Postcondition for a retired step: always satisfied (it did nothing).
	 *
	 * @return bool
	 */
	private function verify_retired(): bool {
		return true;
	}

	/**
	 * Step 37 (ADR-0044 §4b) — forward-only legacy-chat retirement.
	 *
	 * Creates the retained operator-identity map, migrates the mappings
	 * from the obsolete operator_identities table under an exact
	 * (wp_user_id, telegram_user_id) bijection check, and — only if that
	 * holds and obsolete tables remain — sets the retirement marker.
	 * Drops nothing; all destructive removal is `legacy-chat purge`.
	 *
	 * @throws MigrationFailedException If the operator-map bijection fails.
	 */
	private function step_37_retire_legacy_chat(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$map_table       = $wpdb->prefix . self::OPERATOR_IDENTITY_MAP_TABLE;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$map_table} (
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
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$legacy_table = $wpdb->prefix . self::OPERATOR_IDENTITIES_TABLE;

		if ( self::table_exists( $legacy_table ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query(
				"INSERT IGNORE INTO {$map_table}
					(wp_user_id, telegram_user_id, telegram_username, created_at, created_by)
				 SELECT wp_user_id, telegram_user_id, telegram_username, created_at, created_by
				 FROM {$legacy_table}"
			);

			$report = OperatorIdentityMapMigration::verify_bijection();

			if ( ! $report->holds() ) {
				throw new MigrationFailedException( MigrationFailureCode::POSTCONDITION_FAILED );
			}
		}

		if ( $this->any_legacy_table_present() ) {
			update_option( self::LEGACY_CHAT_RETIRED_OPTION, gmdate( 'c' ) );
		}
	}

	/**
	 * Postcondition for step 37: the map table exists with the expected
	 * columns and — if the obsolete source table is still present — the
	 * bijection holds.
	 *
	 * @return bool
	 */
	private function verify_step_37(): bool {
		global $wpdb;

		if ( ! $this->table_has_columns(
			$wpdb->prefix . self::OPERATOR_IDENTITY_MAP_TABLE,
			array( 'id', 'wp_user_id', 'telegram_user_id', 'telegram_username', 'created_at', 'created_by' )
		) ) {
			return false;
		}

		$legacy_table = $wpdb->prefix . self::OPERATOR_IDENTITIES_TABLE;

		return ! self::table_exists( $legacy_table ) || OperatorIdentityMapMigration::verify_bijection()->holds();
	}

	/**
	 * Whether any obsolete ADR-0044 legacy table still exists.
	 *
	 * @return bool
	 */
	private function any_legacy_table_present(): bool {
		global $wpdb;

		foreach ( self::LEGACY_TABLES as $table ) {
			if ( self::table_exists( $wpdb->prefix . $table ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a fully-qualified table name exists in the current schema.
	 *
	 * @param string $qualified_table Prefixed table name.
	 *
	 * @return bool
	 */
	public static function table_exists( string $qualified_table ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (string) $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $qualified_table )
		) === $qualified_table;
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
	 * Adds the notification rule condition-combination mode column (M08.1,
	 * ADR-0032): 'all' (default, matches every existing rule's own current
	 * behavior exactly) or 'any'.
	 */
	private function step_30_add_notification_rule_match_mode_column(): void {
		global $wpdb;

		$table = $wpdb->prefix . self::NOTIFICATION_RULES_TABLE;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// SHOW COLUMNS (this connection) — not INFORMATION_SCHEMA — so a
		// DROP TABLE + recreate in the same PHPUnit process cannot leave a
		// stale "column exists" view that skips the ADD (same precedent as
		// step 29).
		if ( empty( $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'match_mode'" ) ) ) {
			$wpdb->query(
				"ALTER TABLE {$table}
					ADD COLUMN match_mode ENUM('all','any') NOT NULL DEFAULT 'all'"
			);
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Verifies the step's postcondition.
	 *
	 * @return bool
	 */
	private function verify_step_30(): bool {
		global $wpdb;

		return $this->table_has_columns(
			$wpdb->prefix . self::NOTIFICATION_RULES_TABLE,
			array( 'match_mode' )
		);
	}

	/**
	 * Creates Support Chat adapter binding and delivery-idempotency tables
	 * (UT Adapter M1).
	 */
	private function step_31_create_support_chat_adapter_tables(): void {
		global $wpdb;

		$bindings = $wpdb->prefix . self::SUPPORT_CHAT_BINDINGS_TABLE;
		$keys     = $wpdb->prefix . self::SUPPORT_CHAT_DELIVERY_KEYS_TABLE;
		$charset  = $wpdb->get_charset_collate();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$bindings} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				binding_uuid CHAR(36) NOT NULL,
				support_conversation_uuid CHAR(36) NOT NULL,
				ensure_idempotency_key VARCHAR(191) NOT NULL,
				bot_id BIGINT UNSIGNED NOT NULL,
				destination_id BIGINT UNSIGNED NOT NULL,
				telegram_topic_id BIGINT NOT NULL,
				cas_version INT UNSIGNED NOT NULL DEFAULT 1,
				status ENUM('active','unavailable','closed') NOT NULL DEFAULT 'active',
				last_delivered_message_key VARCHAR(191) NULL,
				last_ingest_update_id BIGINT NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY binding_uuid (binding_uuid),
				UNIQUE KEY support_conversation_uuid (support_conversation_uuid),
				UNIQUE KEY ensure_idempotency_key (ensure_idempotency_key),
				UNIQUE KEY bot_topic (bot_id, telegram_topic_id),
				KEY status_idx (status)
			) {$charset}"
		);

		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$keys} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				idempotency_key VARCHAR(191) NOT NULL,
				binding_uuid CHAR(36) NOT NULL,
				outbound_message_uuid CHAR(36) NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY idempotency_key (idempotency_key),
				KEY binding_uuid (binding_uuid)
			) {$charset}"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Verifies Adapter M1 tables.
	 *
	 * @return bool
	 */
	private function verify_step_31(): bool {
		global $wpdb;

		$bindings_ok = $this->table_has_columns(
			$wpdb->prefix . self::SUPPORT_CHAT_BINDINGS_TABLE,
			array(
				'id',
				'binding_uuid',
				'support_conversation_uuid',
				'ensure_idempotency_key',
				'bot_id',
				'destination_id',
				'telegram_topic_id',
				'cas_version',
				'status',
				'last_delivered_message_key',
				'last_ingest_update_id',
				'created_at',
				'updated_at',
			)
		);

		$keys_ok = $this->table_has_columns(
			$wpdb->prefix . self::SUPPORT_CHAT_DELIVERY_KEYS_TABLE,
			array(
				'id',
				'idempotency_key',
				'binding_uuid',
				'outbound_message_uuid',
				'created_at',
			)
		);

		return $bindings_ok && $keys_ok;
	}

	/**
	 * Creates the ADR-0007 signed Contract v1 peer-key and nonce-replay
	 * tables (UT Adapter M1 signed-client follow-up, ADR-0038).
	 */
	private function step_32_create_support_chat_contract_auth_tables(): void {
		global $wpdb;

		$peers   = $wpdb->prefix . self::SUPPORT_CHAT_PEERS_TABLE;
		$nonces  = $wpdb->prefix . self::CONTRACT_NONCES_TABLE;
		$charset = $wpdb->get_charset_collate();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$peers} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				peer_id VARCHAR(191) NOT NULL,
				public_key VARCHAR(191) NOT NULL,
				key_id VARCHAR(191) NOT NULL,
				allowed_operations TEXT NOT NULL,
				required_peer_capability VARCHAR(191) NULL,
				status VARCHAR(16) NOT NULL DEFAULT 'active',
				created_at DATETIME NOT NULL,
				last_rotated_at DATETIME NULL,
				last_used_at DATETIME NULL,
				expires_at DATETIME NULL,
				revoked_at DATETIME NULL,
				PRIMARY KEY (id),
				UNIQUE KEY peer_id (peer_id),
				KEY status_idx (status)
			) {$charset}"
		);

		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$nonces} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				sender VARCHAR(191) NOT NULL,
				key_id VARCHAR(191) NOT NULL,
				nonce VARCHAR(64) NOT NULL,
				recorded_at DATETIME NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY sender_key_nonce (sender, key_id, nonce),
				KEY recorded_at_idx (recorded_at)
			) {$charset}"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Verifies the ADR-0007 signed Contract v1 auth tables.
	 *
	 * @return bool
	 */
	private function verify_step_32(): bool {
		global $wpdb;

		$peers_ok = $this->table_has_columns(
			$wpdb->prefix . self::SUPPORT_CHAT_PEERS_TABLE,
			array(
				'id',
				'peer_id',
				'public_key',
				'key_id',
				'allowed_operations',
				'required_peer_capability',
				'status',
				'created_at',
				'last_rotated_at',
				'last_used_at',
				'expires_at',
				'revoked_at',
			)
		);

		$nonces_ok = $this->table_has_columns(
			$wpdb->prefix . self::CONTRACT_NONCES_TABLE,
			array(
				'id',
				'sender',
				'key_id',
				'nonce',
				'recorded_at',
			)
		);

		return $peers_ok && $nonces_ok;
	}


	/**
	 * Adds the non-routing `prepared` binding status (Support Chat ADR-0009,
	 * ADR-0041), additive only: existing `active`/`unavailable`/`closed`
	 * rows and the existing `active` default are unaffected.
	 * ChannelBinding::is_active() is unchanged and requires no schema
	 * awareness of this new value — a `prepared` row simply falls into its
	 * existing "not active" branch.
	 */
	private function step_34_add_prepared_binding_status(): void {
		global $wpdb;

		$table = $wpdb->prefix . self::SUPPORT_CHAT_BINDINGS_TABLE;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// SHOW COLUMNS (this connection), not INFORMATION_SCHEMA — same
		// stale-cache reasoning as step_29's column checks.
		$column = $wpdb->get_row( "SHOW COLUMNS FROM {$table} LIKE 'status'", ARRAY_A );

		if ( null !== $column && isset( $column['Type'] ) && ! str_contains( (string) $column['Type'], "'prepared'" ) ) {
			$wpdb->query(
				"ALTER TABLE {$table}
					MODIFY COLUMN status ENUM('active','unavailable','closed','prepared') NOT NULL DEFAULT 'active'"
			);
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Verifies the `status` column's ENUM definition includes `prepared`.
	 *
	 * @return bool
	 */
	private function verify_step_34(): bool {
		global $wpdb;

		$table = $wpdb->prefix . self::SUPPORT_CHAT_BINDINGS_TABLE;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$column = $wpdb->get_row( "SHOW COLUMNS FROM {$table} LIKE 'status'", ARRAY_A );

		return null !== $column && isset( $column['Type'] ) && str_contains( (string) $column['Type'], "'prepared'" );
	}

}
