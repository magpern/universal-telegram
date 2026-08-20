<?php
/**
 * Credential decryption states.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Core\Security;

/**
 * The three possible outcomes of CredentialVault::decrypt(). A decryption
 * failure never erases or otherwise modifies the stored ciphertext.
 */
enum CredentialState {
	/**
	 * The plaintext was successfully recovered.
	 */
	case AVAILABLE;

	/**
	 * A ciphertext exists but could not be authenticated under the
	 * currently resolved key (most commonly, WordPress' own salts have
	 * since been rotated).
	 */
	case INVALIDATED;

	/**
	 * No key material could be resolved at all.
	 */
	case UNAVAILABLE;
}
