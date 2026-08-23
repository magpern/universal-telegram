<?php
/**
 * AI provider configuration persistence.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\AI\Config;

use UniversalTelegram\Core\Security\CredentialResult;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Persistence\SchemaHealth;

/**
 * CredentialVault-backed CRUD for the singleton (`id=1`)
 * universal_telegram_ai_config row (docs/adr/0028 decisions 3–5). The row
 * is seeded by Migrator step 19 and always exists once the schema is at
 * version 19 or later — this repository never inserts a new row, only
 * reads and updates the one seeded at migration time. Checks
 * SchemaHealth::is_available() at its own point of use, matching every
 * other M00+ database-touching service (docs/adr/0007).
 *
 * AI can never be enabled without a non-empty encrypted credential and a
 * non-empty, bounded model identifier (docs/milestones/m09-ai-draft-assistant.md):
 * update_settings() fails closed to disabled if that precondition is not
 * met at save time.
 */
final class AIProviderRepository {

	private const CREDENTIAL_CONTEXT = 'ai.provider.api_key:openai';

	/**
	 * Constructor.
	 *
	 * @param SchemaHealth    $schema_health    Checked before every operation.
	 * @param CredentialVault $credential_vault Encrypts/decrypts the provider API key.
	 */
	public function __construct(
		private readonly SchemaHealth $schema_health,
		private readonly CredentialVault $credential_vault
	) {}

	/**
	 * The current configuration, or null if the schema is unavailable.
	 *
	 * @return AIProviderConfig|null
	 */
	public function get(): ?AIProviderConfig {
		if ( ! $this->schema_health->is_available() ) {
			return null;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::AI_CONFIG_TABLE;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( "SELECT * FROM {$table} WHERE id = 1", ARRAY_A );

		if ( null === $row ) {
			return null;
		}

		return $this->hydrate( $row );
	}

	/**
	 * Decrypts the currently stored API key, for outbound provider calls
	 * only. Never called from any admin-rendering or diagnostics path.
	 *
	 * @return CredentialResult|null Null if no credential is configured or the schema is unavailable.
	 */
	public function decrypt_api_key(): ?CredentialResult {
		$config = $this->get();

		if ( null === $config || ! $config->has_credential() ) {
			return null;
		}

		return $this->credential_vault->decrypt( (string) $config->api_key_ciphertext(), self::CREDENTIAL_CONTEXT );
	}

	/**
	 * Updates the model identifier and enablement flag. Fails closed:
	 * $enabled is forced to false if no credential is configured or
	 * $model is empty, regardless of the caller's request. Cancels any
	 * in-flight draft when transitioning from enabled to disabled
	 * (docs/adr/0028 decision 5 — no orphaned in-flight job against a
	 * disabled provider).
	 *
	 * @param string $model   Administrator-entered model identifier.
	 * @param bool   $enabled Requested enablement state.
	 *
	 * @return bool Whether the update succeeded.
	 */
	public function update_settings( string $model, bool $enabled ): bool {
		if ( ! $this->schema_health->is_available() ) {
			return false;
		}

		$current = $this->get();

		if ( null === $current ) {
			return false;
		}

		$model             = trim( $model );
		$has_credential    = $current->has_credential();
		$effective_enabled = $enabled && $has_credential && '' !== $model;

		global $wpdb;

		$table   = $wpdb->prefix . Migrator::AI_CONFIG_TABLE;
		$updated = $wpdb->update(
			$table,
			array(
				'model'      => $model,
				'enabled'    => $effective_enabled ? 1 : 0,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => 1 ),
			array( '%s', '%d', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return false;
		}

		if ( $current->is_enabled() && ! $effective_enabled ) {
			$this->cancel_in_flight_drafts( 'provider_disabled' );
		}

		return true;
	}

	/**
	 * Encrypts and stores a new API key. Does not itself change enablement
	 * — an administrator must still explicitly enable via update_settings().
	 *
	 * @param string $plaintext_key The API key to store.
	 *
	 * @return bool Whether the update succeeded.
	 */
	public function set_credential( string $plaintext_key ): bool {
		if ( ! $this->schema_health->is_available() ) {
			return false;
		}

		global $wpdb;

		$table   = $wpdb->prefix . Migrator::AI_CONFIG_TABLE;
		$updated = $wpdb->update(
			$table,
			array(
				'api_key_ciphertext' => $this->credential_vault->encrypt( $plaintext_key, self::CREDENTIAL_CONTEXT ),
				'updated_at'         => current_time( 'mysql', true ),
			),
			array( 'id' => 1 ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		return false !== $updated;
	}

	/**
	 * Clears the stored credential and forces enablement off in the same
	 * update. Cancels any in-flight draft (docs/adr/0028 decision 5).
	 *
	 * @return bool Whether the update succeeded.
	 */
	public function delete_credential(): bool {
		if ( ! $this->schema_health->is_available() ) {
			return false;
		}

		global $wpdb;

		$table   = $wpdb->prefix . Migrator::AI_CONFIG_TABLE;
		$updated = $wpdb->update(
			$table,
			array(
				'api_key_ciphertext' => null,
				'enabled'            => 0,
				'updated_at'         => current_time( 'mysql', true ),
			),
			array( 'id' => 1 ),
			array( '%s', '%d', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return false;
		}

		$this->cancel_in_flight_drafts( 'provider_disabled' );

		return true;
	}

	/**
	 * Bumps the disclosure text/version. A deliberate, warned action: every
	 * conversation acknowledged under the prior version becomes permanently
	 * ineligible for new drafts (docs/adr/0028 decision 1) — no backfill,
	 * no re-prompt.
	 *
	 * @param string $new_version A new, distinct version tag.
	 * @param string $new_text    The new disclosure copy.
	 *
	 * @return bool Whether the update succeeded.
	 */
	public function bump_ack_policy( string $new_version, string $new_text ): bool {
		if ( ! $this->schema_health->is_available() ) {
			return false;
		}

		global $wpdb;

		$table   = $wpdb->prefix . Migrator::AI_CONFIG_TABLE;
		$updated = $wpdb->update(
			$table,
			array(
				'ack_policy_version' => $new_version,
				'ack_text'           => $new_text,
				'updated_at'         => current_time( 'mysql', true ),
			),
			array( 'id' => 1 ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		return false !== $updated;
	}

	/**
	 * Transitions every `queued`/`generating` draft to `failed` with the
	 * given failure class, clearing any lease. Issued directly against the
	 * drafts table (rather than through AI\Draft\AiDraftRepository) because
	 * this repository owns the provider-lifecycle event (disable/delete)
	 * that makes every in-flight job invalid, independent of the draft
	 * domain's own claim/lease machinery.
	 *
	 * @param string $failure_class Fixed failure taxonomy code.
	 */
	private function cancel_in_flight_drafts( string $failure_class ): void {
		global $wpdb;

		$table = $wpdb->prefix . Migrator::AI_DRAFTS_TABLE;

		// wpdb::update() only supports column=value WHERE equality; the
		// "status IN (...)" clause here requires a direct prepared query.
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE {$table} SET status = %s, failure_class = %s, lease_token = NULL, generation_lease_expires_at = NULL, updated_at = %s WHERE status IN ('queued','generating')",
				'failed',
				$failure_class,
				current_time( 'mysql', true )
			)
		);
	}

	/**
	 * Builds a value object from a raw database row.
	 *
	 * @param array<string, mixed> $row Raw row from $wpdb->get_row(..., ARRAY_A).
	 *
	 * @return AIProviderConfig
	 */
	private function hydrate( array $row ): AIProviderConfig {
		return new AIProviderConfig(
			(string) $row['provider'],
			(string) $row['model'],
			null !== $row['api_key_ciphertext'] ? (string) $row['api_key_ciphertext'] : null,
			(bool) (int) $row['enabled'],
			(string) $row['ack_policy_version'],
			(string) $row['ack_text'],
			(string) $row['created_at'],
			(string) $row['updated_at']
		);
	}
}
