<?php
/**
 * Bot profile persistence.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Telegram\Configuration;

use UniversalTelegram\Core\Security\CredentialResult;
use UniversalTelegram\Core\Security\CredentialState;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Persistence\SchemaHealth;

/**
 * CredentialVault-backed CRUD for bot profiles, including the pure-data
 * read/write/promote primitives on the pending webhook-secret fields used by
 * WebhookSecretVerifier (traffic-based confirmation) and
 * WebhookRegistrationCoordinator (register/rotate/retry/rollback). This
 * repository performs no remote (Telegram API) validation of any kind — a
 * token or secret replace here is an unconditional ciphertext write;
 * validate-before-commit behaviour is the admin controller's own
 * responsibility (WP10), built on top of this repository and
 * TelegramApiClient (WP4). Checks SchemaHealth::is_available() at its own
 * point of use, like every other M00/M01 database-touching service
 * (docs/adr/0007).
 */
final class BotProfileRepository {

	private const TOKEN_CONTEXT_PREFIX  = 'telegram.token:';
	private const SECRET_CONTEXT_PREFIX = 'telegram.webhook_secret:';

	/**
	 * Fired once, after every successful bot-row write (create, any field
	 * update, or delete) in this repository, regardless of caller — the
	 * manual Bots tab, the setup wizard, the webhook-registration flow, or
	 * any future caller. Carries no arguments by design: a listener that
	 * cares about validity (e.g. Automations\Digest\DigestEligibility)
	 * re-reads live state itself rather than trusting a payload describing
	 * what changed (docs/plans/m11a-visitor-activity-digests-plan-v1.md §3.1).
	 */
	public const CHANGED_ACTION = 'universal_telegram_bot_or_destination_changed';

	/**
	 * Constructor.
	 *
	 * @param SchemaHealth    $schema_health    Checked before every operation.
	 * @param CredentialVault $credential_vault Encrypts/decrypts token and webhook-secret material.
	 */
	public function __construct(
		private readonly SchemaHealth $schema_health,
		private readonly CredentialVault $credential_vault
	) {}

	/**
	 * Creates a new bot profile with a fresh bot_uuid and a fresh active
	 * webhook secret. Every bot row exists with an active secret from the
	 * moment it is created (docs/adr/0013) — there is no bot state with no
	 * active secret.
	 *
	 * @param string $name            Admin-facing label.
	 * @param string $token_plaintext The bot's Telegram token.
	 *
	 * @return BotProfile|null Null if the schema is unavailable or the insert failed.
	 */
	public function create( string $name, string $token_plaintext ): ?BotProfile {
		if ( ! $this->schema_health->is_available() ) {
			return null;
		}

		global $wpdb;

		$bot_uuid       = wp_generate_uuid4();
		$webhook_secret = bin2hex( random_bytes( 24 ) );
		$now            = current_time( 'mysql', true );

		$table    = $wpdb->prefix . Migrator::BOTS_TABLE;
		$inserted = $wpdb->insert(
			$table,
			array(
				'bot_uuid'                   => $bot_uuid,
				'name'                       => $name,
				'token_ciphertext'           => $this->credential_vault->encrypt( $token_plaintext, self::TOKEN_CONTEXT_PREFIX . $bot_uuid ),
				'webhook_secret_ciphertext'  => $this->credential_vault->encrypt( $webhook_secret, self::SECRET_CONTEXT_PREFIX . $bot_uuid ),
				'webhook_registration_state' => 'unregistered',
				'status'                     => BotStatus::UNCONFIGURED->value,
				'created_at'                 => $now,
				'updated_at'                 => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return null;
		}

		/**
		 * Fires after a bot or destination row changes.
		 *
		 * @since 0.5.0
		 */
		do_action( self::CHANGED_ACTION ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- constant value is universal_telegram_bot_or_destination_changed.

		return $this->find( (int) $wpdb->insert_id );
	}

	/**
	 * Finds a bot profile by primary key.
	 *
	 * @param int $id The bot's primary key.
	 *
	 * @return BotProfile|null
	 */
	public function find( int $id ): ?BotProfile {
		if ( ! $this->schema_health->is_available() ) {
			return null;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::BOTS_TABLE;
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return null === $row ? null : $this->hydrate( $row );
	}

	/**
	 * Finds a bot profile by its opaque webhook-route UUID.
	 *
	 * @param string $bot_uuid The bot's opaque UUID.
	 *
	 * @return BotProfile|null
	 */
	public function find_by_uuid( string $bot_uuid ): ?BotProfile {
		if ( ! $this->schema_health->is_available() ) {
			return null;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::BOTS_TABLE;
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE bot_uuid = %s", $bot_uuid ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return null === $row ? null : $this->hydrate( $row );
	}

	/**
	 * Every configured bot profile.
	 *
	 * @return array<int, BotProfile>
	 */
	public function all(): array {
		if ( ! $this->schema_health->is_available() ) {
			return array();
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::BOTS_TABLE;
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared

		return array_map( array( $this, 'hydrate' ), null === $rows ? array() : $rows );
	}

	/**
	 * Unconditionally replaces the stored token ciphertext. No remote
	 * validation of any kind occurs at this layer.
	 *
	 * @param int    $id              The bot's primary key.
	 * @param string $token_plaintext The new token.
	 *
	 * @return bool
	 */
	public function replace_token( int $id, string $token_plaintext ): bool {
		$bot = $this->find( $id );

		if ( null === $bot ) {
			return false;
		}

		return $this->update(
			$id,
			array( 'token_ciphertext' => $this->credential_vault->encrypt( $token_plaintext, self::TOKEN_CONTEXT_PREFIX . $bot->bot_uuid() ) )
		);
	}

	/**
	 * Decrypts a bot's own token.
	 *
	 * @param BotProfile $bot The bot.
	 *
	 * @return CredentialResult
	 */
	public function decrypt_token( BotProfile $bot ): CredentialResult {
		return $this->credential_vault->decrypt( $bot->token_ciphertext(), self::TOKEN_CONTEXT_PREFIX . $bot->bot_uuid() );
	}

	/**
	 * Decrypts a bot's own active webhook secret.
	 *
	 * @param BotProfile $bot The bot.
	 *
	 * @return CredentialResult
	 */
	public function decrypt_webhook_secret( BotProfile $bot ): CredentialResult {
		return $this->credential_vault->decrypt( $bot->webhook_secret_ciphertext(), self::SECRET_CONTEXT_PREFIX . $bot->bot_uuid() );
	}

	/**
	 * Decrypts a bot's own pending webhook secret, if one is currently set.
	 *
	 * @param BotProfile $bot The bot.
	 *
	 * @return CredentialResult|null Null if no pending secret is currently set.
	 */
	public function decrypt_pending_webhook_secret( BotProfile $bot ): ?CredentialResult {
		if ( ! $bot->has_pending_secret() ) {
			return null;
		}

		return $this->credential_vault->decrypt( (string) $bot->webhook_secret_pending_ciphertext(), self::SECRET_CONTEXT_PREFIX . $bot->bot_uuid() );
	}

	/**
	 * Starts a rotation: writes a genuinely new secret to the pending slot.
	 * The active slot is never touched by this method.
	 *
	 * @param int    $id                       The bot's primary key.
	 * @param string $pending_secret_plaintext The newly generated pending secret.
	 *
	 * @return bool
	 */
	public function start_pending_secret( int $id, string $pending_secret_plaintext ): bool {
		$bot = $this->find( $id );

		if ( null === $bot ) {
			return false;
		}

		return $this->update(
			$id,
			array(
				'webhook_secret_pending_ciphertext' => $this->credential_vault->encrypt( $pending_secret_plaintext, self::SECRET_CONTEXT_PREFIX . $bot->bot_uuid() ),
				'webhook_secret_pending_since'      => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * Promotes the current pending secret to active and clears the pending
	 * slot, then marks the bot registered. A pure data operation — never
	 * itself calls Telegram; the caller (WebhookSecretVerifier on traffic
	 * confirmation, or WebhookRegistrationCoordinator on a clean
	 * retry/rotate success) has already established the promotion is
	 * warranted.
	 *
	 * @param int $id The bot's primary key.
	 *
	 * @return bool
	 */
	public function promote_pending_secret( int $id ): bool {
		$bot = $this->find( $id );

		if ( null === $bot || ! $bot->has_pending_secret() ) {
			return false;
		}

		$now = current_time( 'mysql', true );

		return $this->update(
			$id,
			array(
				'webhook_secret_ciphertext'         => $bot->webhook_secret_pending_ciphertext(),
				'webhook_secret_pending_ciphertext' => null,
				'webhook_secret_pending_since'      => null,
				'webhook_registration_state'        => 'registered',
				'webhook_registered_at'             => $now,
			)
		);
	}

	/**
	 * Discards the current pending secret without touching the active
	 * secret or the registration state. Used on a clean, definite
	 * rotate()/retry_pending() failure.
	 *
	 * @param int $id The bot's primary key.
	 *
	 * @return bool
	 */
	public function discard_pending_secret( int $id ): bool {
		return $this->update(
			$id,
			array(
				'webhook_secret_pending_ciphertext' => null,
				'webhook_secret_pending_since'      => null,
			)
		);
	}

	/**
	 * Marks the bot's webhook registration state 'registered', and its own
	 * status 'active' if it was still 'unconfigured'.
	 *
	 * @param int $id The bot's primary key.
	 *
	 * @return bool
	 */
	public function mark_registered( int $id ): bool {
		$bot = $this->find( $id );

		if ( null === $bot ) {
			return false;
		}

		$fields = array(
			'webhook_registration_state' => 'registered',
			'webhook_registered_at'      => current_time( 'mysql', true ),
		);

		if ( BotStatus::UNCONFIGURED === $bot->status() ) {
			$fields['status'] = BotStatus::ACTIVE->value;
		}

		return $this->update( $id, $fields );
	}

	/**
	 * Marks the bot's webhook registration state 'unregistered' — a clean,
	 * definite registration failure with nothing to reconcile.
	 *
	 * @param int $id The bot's primary key.
	 *
	 * @return bool
	 */
	public function mark_unregistered( int $id ): bool {
		return $this->update( $id, array( 'webhook_registration_state' => 'unregistered' ) );
	}

	/**
	 * Marks the bot's webhook registration state 'uncertain'. Never itself
	 * touches any secret field.
	 *
	 * @param int $id The bot's primary key.
	 *
	 * @return bool
	 */
	public function mark_uncertain( int $id ): bool {
		return $this->update( $id, array( 'webhook_registration_state' => 'uncertain' ) );
	}

	/**
	 * Unconditionally records the timestamp of the most recent
	 * register/rotate/retry_pending/rollback attempt, regardless of its
	 * outcome.
	 *
	 * @param int $id The bot's primary key.
	 *
	 * @return bool
	 */
	public function touch_last_attempt( int $id ): bool {
		return $this->update( $id, array( 'webhook_last_attempt_at' => current_time( 'mysql', true ) ) );
	}

	/**
	 * Records the bot's own Telegram identity, as returned by getMe.
	 *
	 * @param int    $id                The bot's primary key.
	 * @param int    $telegram_bot_id   Telegram's own numeric bot ID.
	 * @param string $telegram_username Telegram's own bot username.
	 *
	 * @return bool
	 */
	public function update_telegram_identity( int $id, int $telegram_bot_id, string $telegram_username ): bool {
		return $this->update(
			$id,
			array(
				'telegram_bot_id'   => $telegram_bot_id,
				'telegram_username' => $telegram_username,
			)
		);
	}

	/**
	 * Sets the bot's own operational status.
	 *
	 * @param int       $id     The bot's primary key.
	 * @param BotStatus $status The new status.
	 *
	 * @return bool
	 */
	public function set_status( int $id, BotStatus $status ): bool {
		return $this->update( $id, array( 'status' => $status->value ) );
	}

	/**
	 * Deletes a bot profile and its own row only. Destinations and delivery
	 * history belonging to it are the caller's own responsibility to
	 * reconcile; this method performs no cascading delete.
	 *
	 * @param int $id The bot's primary key.
	 *
	 * @return bool
	 */
	public function delete( int $id ): bool {
		if ( ! $this->schema_health->is_available() ) {
			return false;
		}

		global $wpdb;

		$table   = $wpdb->prefix . Migrator::BOTS_TABLE;
		$deleted = false !== $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );

		if ( $deleted ) {
			/**
			 * Fires after a bot or destination row changes.
			 *
			 * @since 0.5.0
			 */
			do_action( self::CHANGED_ACTION ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- constant value is universal_telegram_bot_or_destination_changed.
		}

		return $deleted;
	}

	/**
	 * Counts bots whose most recent registration or rotation attempt is
	 * unresolved ('uncertain') and older than the given threshold, measured
	 * from the correct case-specific timestamp: webhook_secret_pending_since
	 * when a rotation is in progress, webhook_last_attempt_at otherwise.
	 * created_at/updated_at are never used. A read-only diagnostic count —
	 * never writes to, discards, replaces, or promotes anything.
	 *
	 * @param int $threshold_hours The staleness threshold, in hours.
	 *
	 * @return int
	 */
	public function count_stale_unresolved_registrations( int $threshold_hours ): int {
		if ( ! $this->schema_health->is_available() ) {
			return 0;
		}

		global $wpdb;

		$table  = $wpdb->prefix . Migrator::BOTS_TABLE;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $threshold_hours * HOUR_IN_SECONDS ) );

		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE webhook_registration_state = 'uncertain' AND (" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				. '(webhook_secret_pending_ciphertext IS NOT NULL AND webhook_secret_pending_since IS NOT NULL AND webhook_secret_pending_since < %s)'
				. ' OR '
				. '(webhook_secret_pending_ciphertext IS NULL AND webhook_last_attempt_at IS NOT NULL AND webhook_last_attempt_at < %s)'
				. ')',
				$cutoff,
				$cutoff
			)
		);

		return (int) $count;
	}

	/**
	 * Shared partial-update helper.
	 *
	 * @param int                  $id     The bot's primary key.
	 * @param array<string, mixed> $fields The columns to update.
	 *
	 * @return bool
	 */
	private function update( int $id, array $fields ): bool {
		if ( ! $this->schema_health->is_available() ) {
			return false;
		}

		global $wpdb;

		$fields['updated_at'] = current_time( 'mysql', true );

		$table   = $wpdb->prefix . Migrator::BOTS_TABLE;
		$updated = $wpdb->update( $table, $fields, array( 'id' => $id ) );

		$succeeded = false !== $updated;

		if ( $succeeded ) {
			/**
			 * Fires after a bot or destination row changes.
			 *
			 * @since 0.5.0
			 */
			do_action( self::CHANGED_ACTION ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- constant value is universal_telegram_bot_or_destination_changed.
		}

		return $succeeded;
	}

	/**
	 * Hydrates one database row into a BotProfile.
	 *
	 * @param array<string, mixed> $row The raw database row.
	 *
	 * @return BotProfile
	 */
	private function hydrate( array $row ): BotProfile {
		return new BotProfile(
			(int) $row['id'],
			(string) $row['bot_uuid'],
			(string) $row['name'],
			(string) $row['token_ciphertext'],
			(string) $row['webhook_secret_ciphertext'],
			null === $row['webhook_secret_pending_ciphertext'] ? null : (string) $row['webhook_secret_pending_ciphertext'],
			null === $row['webhook_secret_pending_since'] ? null : (string) $row['webhook_secret_pending_since'],
			(string) $row['webhook_registration_state'],
			null === $row['webhook_last_attempt_at'] ? null : (string) $row['webhook_last_attempt_at'],
			null === $row['telegram_bot_id'] ? null : (int) $row['telegram_bot_id'],
			null === $row['telegram_username'] ? null : (string) $row['telegram_username'],
			BotStatus::from( (string) $row['status'] ),
			null === $row['webhook_registered_at'] ? null : (string) $row['webhook_registered_at'],
			(string) $row['created_at'],
			(string) $row['updated_at']
		);
	}

	/**
	 * The CredentialVault context bound to a bot's own token.
	 *
	 * @param string $bot_uuid The bot's opaque UUID.
	 *
	 * @return string
	 */
	public static function token_context( string $bot_uuid ): string {
		return self::TOKEN_CONTEXT_PREFIX . $bot_uuid;
	}

	/**
	 * The CredentialVault context bound to a bot's own webhook secret slots.
	 *
	 * @param string $bot_uuid The bot's opaque UUID.
	 *
	 * @return string
	 */
	public static function secret_context( string $bot_uuid ): string {
		return self::SECRET_CONTEXT_PREFIX . $bot_uuid;
	}
}
