<?php
/**
 * This plugin's own Ed25519 key pair (ADR-0007 §1-§2).
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\SupportChatAdapter\Auth;

use UniversalTelegram\Core\Security\CredentialState;
use UniversalTelegram\Core\Security\CredentialUnavailableException;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\SupportChatAdapter\ContractConstants;

/**
 * Generates and retains this plugin's own Ed25519 key pair. The private key
 * is encrypted in this plugin's own CredentialVault (reusing the existing
 * mechanism, never a new one) and never leaves this class; only the public
 * key and key ID are ever exposed to callers.
 */
class OwnKeyManager {

	public const OPTION_PUBLIC = 'universal_telegram_support_chat_contract_own_key';

	public const OPTION_SECRET = 'universal_telegram_support_chat_contract_own_key_secret';

	private const VAULT_CONTEXT = 'support_chat_adapter.contract_own_signing_key';

	/**
	 * Constructor.
	 *
	 * @param CredentialVault $vault Plugin's own credential vault.
	 */
	public function __construct( private readonly CredentialVault $vault ) {}

	/**
	 * Generates a key pair if one does not already exist. Idempotent.
	 *
	 * @return array{public_key: string, key_id: string}|null Public key
	 *              (base64) and key ID, or null if a key could not be
	 *              generated or stored.
	 */
	public function ensure_key_pair(): ?array {
		$existing = $this->public_key();
		if ( null !== $existing ) {
			return $existing;
		}

		return $this->generate();
	}

	/**
	 * The current public key and key ID, if a key pair exists.
	 *
	 * @return array{public_key: string, key_id: string}|null
	 */
	public function public_key(): ?array {
		$stored = get_option( self::OPTION_PUBLIC, null );

		if ( ! is_array( $stored ) || ! isset( $stored['public_key'], $stored['key_id'] ) ) {
			return null;
		}

		return array(
			'public_key' => (string) $stored['public_key'],
			'key_id'     => (string) $stored['key_id'],
		);
	}

	/**
	 * Rotates to a brand-new key pair. The prior public key remains
	 * recorded nowhere once overwritten — only the new key is current.
	 * Per ADR-0007 §2, rotation never propagates automatically: Support
	 * Chat must re-pair against the new key ID.
	 *
	 * @return array{public_key: string, key_id: string}|null
	 */
	public function rotate(): ?array {
		return $this->generate();
	}

	/**
	 * The raw 64-byte secret key, for signing outbound calls to Support
	 * Chat. Never logged, never returned from a REST endpoint.
	 */
	public function secret_key_raw(): ?string {
		$stored = get_option( self::OPTION_SECRET, null );

		if ( ! is_string( $stored ) || '' === $stored ) {
			return null;
		}

		try {
			$result = $this->vault->decrypt( $stored, self::VAULT_CONTEXT );
		} catch ( CredentialUnavailableException $exception ) {
			return null;
		}

		if ( CredentialState::AVAILABLE !== $result->state() ) {
			return null;
		}

		return $result->plaintext();
	}

	/**
	 * Generates, stores, and returns a fresh key pair.
	 *
	 * @return array{public_key: string, key_id: string}|null
	 */
	private function generate(): ?array {
		if ( ! function_exists( 'sodium_crypto_sign_keypair' ) ) {
			return null;
		}

		$pair       = sodium_crypto_sign_keypair();
		$public_raw = sodium_crypto_sign_publickey( $pair );
		$secret_raw = sodium_crypto_sign_secretkey( $pair );

		try {
			$envelope = $this->vault->encrypt( $secret_raw, self::VAULT_CONTEXT );
		} catch ( CredentialUnavailableException $exception ) {
			return null;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- transport encoding, not obfuscation.
		$public_key_base64 = base64_encode( $public_raw );
		$key_id            = KeyId::compute( ContractConstants::SELF_ID, $public_raw );

		update_option( self::OPTION_SECRET, $envelope, false );
		update_option(
			self::OPTION_PUBLIC,
			array(
				'public_key' => $public_key_base64,
				'key_id'     => $key_id,
				'created_at' => current_time( 'mysql', true ),
			),
			false
		);

		return array(
			'public_key' => $public_key_base64,
			'key_id'     => $key_id,
		);
	}

	/**
	 * Deletes the stored key pair (uninstall only).
	 */
	public function delete(): void {
		delete_option( self::OPTION_PUBLIC );
		delete_option( self::OPTION_SECRET );
	}
}
