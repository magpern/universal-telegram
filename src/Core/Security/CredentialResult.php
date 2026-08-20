<?php
/**
 * Credential decryption result.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Core\Security;

/**
 * The outcome of CredentialVault::decrypt(): a state, and the recovered
 * plaintext only when that state is AVAILABLE.
 */
final class CredentialResult {

	/**
	 * The outcome.
	 *
	 * @var CredentialState
	 */
	private CredentialState $state;

	/**
	 * The recovered plaintext, only when state is AVAILABLE.
	 *
	 * @var string|null
	 */
	private ?string $plaintext;

	/**
	 * Constructor.
	 *
	 * @param CredentialState $state     The outcome.
	 * @param string|null     $plaintext The recovered plaintext, only when
	 *                                    $state is AVAILABLE.
	 */
	public function __construct( CredentialState $state, ?string $plaintext = null ) {
		$this->state     = $state;
		$this->plaintext = $plaintext;
	}

	/**
	 * The outcome.
	 *
	 * @return CredentialState
	 */
	public function state(): CredentialState {
		return $this->state;
	}

	/**
	 * The recovered plaintext, only when state() is AVAILABLE.
	 *
	 * @return string|null
	 */
	public function plaintext(): ?string {
		return $this->plaintext;
	}
}
