<?php
/**
 * Fixed-key OwnKeyManager test double (no WordPress/CredentialVault dependency).
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Tests\SupportChatAdapter\Auth\Support;

use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\SupportChatAdapter\Auth\KeyId;
use UniversalTelegram\SupportChatAdapter\Auth\OwnKeyManager;
use UniversalTelegram\SupportChatAdapter\ContractConstants;

/**
 * Wraps a deterministic, test-generated Ed25519 key pair so
 * SignatureSigner can be unit-tested without touching get_option()/
 * CredentialVault.
 */
final class FakeOwnKeyManager extends OwnKeyManager {

	private string $public_raw;

	private string $secret_raw;

	private ?string $key_id_override;

	public function __construct( ?string $public_raw = null, ?string $secret_raw = null, ?string $key_id_override = null ) {
		parent::__construct( new CredentialVault() );

		if ( null === $public_raw || null === $secret_raw ) {
			$pair             = sodium_crypto_sign_keypair();
			$this->public_raw = sodium_crypto_sign_publickey( $pair );
			$this->secret_raw = sodium_crypto_sign_secretkey( $pair );
		} else {
			$this->public_raw = $public_raw;
			$this->secret_raw = $secret_raw;
		}

		$this->key_id_override = $key_id_override;
	}

	public function public_key(): ?array {
		return array(
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- test fixture, not obfuscation.
			'public_key' => base64_encode( $this->public_raw ),
			'key_id'     => $this->key_id_override ?? KeyId::compute( ContractConstants::SELF_ID, $this->public_raw ),
		);
	}

	public function secret_key_raw(): ?string {
		return $this->secret_raw;
	}

	public function ensure_key_pair(): ?array {
		return $this->public_key();
	}

	public function rotate(): ?array {
		return $this->public_key();
	}

	public function raw_public_key(): string {
		return $this->public_raw;
	}
}
