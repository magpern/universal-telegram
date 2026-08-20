<?php
/**
 * Fail-closed credential encryption.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Core\Security;

/**
 * AES-256-GCM secret storage with three-tier fail-closed key resolution.
 * A decryption failure never erases or otherwise modifies the stored
 * ciphertext (docs/adr/0008).
 *
 * Not declared final: tests/unit/Core/Security/CredentialVaultTest overrides
 * explicit_key_constant() to exercise the malformed-constant and
 * key-material-changed scenarios deterministically. PHP constants cannot be
 * redefined within one process, so this is the only way to exercise both a
 * malformed value and a second, different valid value against the same
 * tier without relying on process isolation. Production code never
 * overrides it; the default implementation reads the real constant.
 */
class CredentialVault {

	private const ENVELOPE_VERSION = 'ut1:';
	private const CIPHER           = 'aes-256-gcm';
	private const NONCE_LENGTH     = 12;
	private const TAG_LENGTH       = 16;

	private const KEY_SOURCE_EXPLICIT_CONSTANT = 1;
	private const KEY_SOURCE_WP_AUTH_SALTS     = 2;
	private const KEY_SOURCE_WP_SITE_SALT      = 3;

	private const WP_DEFAULT_SALT_PLACEHOLDER = 'put your unique phrase here';

	/**
	 * Encrypts a plaintext value.
	 *
	 * @param string $plaintext The value to encrypt.
	 * @param string $context   Authenticated additional data, binding the
	 *                          ciphertext to what it was encrypted for.
	 *
	 * @return string The stored envelope string.
	 *
	 * @throws CredentialUnavailableException If no key material can be resolved.
	 */
	public function encrypt( string $plaintext, string $context ): string {
		list( $key, $source ) = $this->resolve_key();

		$nonce = random_bytes( self::NONCE_LENGTH );
		$tag   = '';

		$ciphertext = openssl_encrypt( $plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $nonce, $tag, $context, self::TAG_LENGTH );

		if ( false === $ciphertext ) {
			throw new CredentialUnavailableException( 'Encryption failed.' );
		}

		$payload = chr( $source ) . $nonce . $tag . $ciphertext;

		// Binary-to-text transport encoding for a database TEXT column,
		// not obfuscation.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return self::ENVELOPE_VERSION . base64_encode( $payload );
	}

	/**
	 * Decrypts a stored envelope string. Never throws; a decryption
	 * failure is reported as a state, never as an exception, and the
	 * stored value is never modified.
	 *
	 * @param string $stored  The stored envelope string.
	 * @param string $context The same authenticated additional data used
	 *                        to encrypt it.
	 *
	 * @return CredentialResult
	 */
	public function decrypt( string $stored, string $context ): CredentialResult {
		$envelope = $this->parse_envelope( $stored );

		if ( null === $envelope ) {
			return new CredentialResult( CredentialState::INVALIDATED );
		}

		try {
			$key = $this->resolve_key_for_source( $envelope['source'] );
		} catch ( CredentialUnavailableException $exception ) {
			return new CredentialResult( CredentialState::UNAVAILABLE );
		}

		return $this->decrypt_envelope( $envelope, $key, $context );
	}

	/**
	 * Decrypts a stored value using either the currently resolved key or
	 * an explicitly supplied override, then re-encrypts it under whichever
	 * key is currently resolved. The primitive a future rotation workflow
	 * would build on.
	 *
	 * @param string      $stored           The stored envelope string.
	 * @param string      $context          The authenticated additional data.
	 * @param string|null $override_key_hex A 64-character hex key to decrypt
	 *                                       with instead of the currently
	 *                                       resolved key.
	 *
	 * @return string The freshly encrypted envelope string.
	 *
	 * @throws CredentialUnavailableException If decryption or the final
	 *                                          re-encryption fails.
	 */
	public function reencrypt( string $stored, string $context, ?string $override_key_hex = null ): string {
		if ( null === $override_key_hex ) {
			$result = $this->decrypt( $stored, $context );

			if ( CredentialState::AVAILABLE !== $result->state() || null === $result->plaintext() ) {
				throw new CredentialUnavailableException( 'Cannot decrypt for re-encryption.' );
			}

			$plaintext = $result->plaintext();
		} else {
			$override_key = $this->decode_explicit_key( $override_key_hex );

			if ( null === $override_key ) {
				throw new CredentialUnavailableException( 'Override key is malformed.' );
			}

			$envelope = $this->parse_envelope( $stored );

			if ( null === $envelope ) {
				throw new CredentialUnavailableException( 'Stored value is malformed.' );
			}

			$decrypted = $this->decrypt_envelope( $envelope, $override_key, $context );

			if ( CredentialState::AVAILABLE !== $decrypted->state() || null === $decrypted->plaintext() ) {
				throw new CredentialUnavailableException( 'Cannot decrypt with the supplied override key.' );
			}

			$plaintext = $decrypted->plaintext();
		}

		return $this->encrypt( $plaintext, $context );
	}

	/**
	 * Parses a stored envelope string into its component parts.
	 *
	 * @param string $stored The stored envelope string.
	 *
	 * @return array{source: int, nonce: string, tag: string, ciphertext: string}|null
	 */
	private function parse_envelope( string $stored ): ?array {
		if ( ! str_starts_with( $stored, self::ENVELOPE_VERSION ) ) {
			return null;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$decoded = base64_decode( substr( $stored, strlen( self::ENVELOPE_VERSION ) ), true );

		$minimum_length = 1 + self::NONCE_LENGTH + self::TAG_LENGTH;

		if ( false === $decoded || strlen( $decoded ) < $minimum_length ) {
			return null;
		}

		return array(
			'source'     => ord( $decoded[0] ),
			'nonce'      => substr( $decoded, 1, self::NONCE_LENGTH ),
			'tag'        => substr( $decoded, 1 + self::NONCE_LENGTH, self::TAG_LENGTH ),
			'ciphertext' => substr( $decoded, 1 + self::NONCE_LENGTH + self::TAG_LENGTH ),
		);
	}

	/**
	 * Decrypts an already-parsed envelope with a specific, already-resolved key.
	 *
	 * @param array{source: int, nonce: string, tag: string, ciphertext: string} $envelope The parsed envelope.
	 * @param string                                                             $key      Raw key bytes.
	 * @param string                                                             $context  The authenticated additional data.
	 *
	 * @return CredentialResult
	 */
	private function decrypt_envelope( array $envelope, string $key, string $context ): CredentialResult {
		$plaintext = openssl_decrypt(
			$envelope['ciphertext'],
			self::CIPHER,
			$key,
			OPENSSL_RAW_DATA,
			$envelope['nonce'],
			$envelope['tag'],
			$context
		);

		if ( false === $plaintext ) {
			return new CredentialResult( CredentialState::INVALIDATED );
		}

		return new CredentialResult( CredentialState::AVAILABLE, $plaintext );
	}

	/**
	 * Resolves the current key, trying each tier in order.
	 *
	 * @return array{0: string, 1: int}
	 *
	 * @throws CredentialUnavailableException If no tier resolves.
	 */
	private function resolve_key(): array {
		$explicit = $this->explicit_key_constant();

		if ( null !== $explicit ) {
			$key = $this->decode_explicit_key( $explicit );

			if ( null === $key ) {
				throw new CredentialUnavailableException( 'UNIVERSAL_TELEGRAM_CREDENTIAL_KEY is defined but malformed.' );
			}

			return array( $key, self::KEY_SOURCE_EXPLICIT_CONSTANT );
		}

		$wp_auth_key = $this->resolve_wp_auth_salts();

		if ( null !== $wp_auth_key ) {
			return array( $wp_auth_key, self::KEY_SOURCE_WP_AUTH_SALTS );
		}

		return array( hash( 'sha256', wp_salt( 'auth' ), true ), self::KEY_SOURCE_WP_SITE_SALT );
	}

	/**
	 * Resolves key material for one specific, already-recorded tier.
	 *
	 * @param int $source One of the KEY_SOURCE_* constants.
	 *
	 * @return string Raw key bytes.
	 *
	 * @throws CredentialUnavailableException If that specific tier cannot resolve.
	 */
	private function resolve_key_for_source( int $source ): string {
		if ( self::KEY_SOURCE_EXPLICIT_CONSTANT === $source ) {
			$explicit = $this->explicit_key_constant();

			if ( null === $explicit ) {
				throw new CredentialUnavailableException( 'UNIVERSAL_TELEGRAM_CREDENTIAL_KEY is not defined.' );
			}

			$key = $this->decode_explicit_key( $explicit );

			if ( null === $key ) {
				throw new CredentialUnavailableException( 'UNIVERSAL_TELEGRAM_CREDENTIAL_KEY is malformed.' );
			}

			return $key;
		}

		if ( self::KEY_SOURCE_WP_AUTH_SALTS === $source ) {
			$key = $this->resolve_wp_auth_salts();

			if ( null === $key ) {
				throw new CredentialUnavailableException( 'WordPress auth salts are not fully configured.' );
			}

			return $key;
		}

		if ( self::KEY_SOURCE_WP_SITE_SALT === $source ) {
			return hash( 'sha256', wp_salt( 'auth' ), true );
		}

		throw new CredentialUnavailableException( 'Unknown key source.' );
	}

	/**
	 * The raw value of the explicit key constant, if defined. Overridable
	 * by tests; see the class docblock.
	 *
	 * @return string|null
	 */
	protected function explicit_key_constant(): ?string {
		return defined( 'UNIVERSAL_TELEGRAM_CREDENTIAL_KEY' ) ? UNIVERSAL_TELEGRAM_CREDENTIAL_KEY : null;
	}

	/**
	 * Decodes and validates a 64-character hex key string.
	 *
	 * @param string $value The constant's raw value.
	 *
	 * @return string|null Raw 32-byte key, or null if malformed.
	 */
	private function decode_explicit_key( string $value ): ?string {
		if ( 1 !== preg_match( '/^[0-9a-fA-F]{64}$/', $value ) ) {
			return null;
		}

		$decoded = hex2bin( $value );

		return false === $decoded ? null : $decoded;
	}

	/**
	 * Resolves key material from WordPress' own four auth-related salts.
	 *
	 * @return string|null Raw key bytes, or null if any of the four
	 *                       constants is undefined or still holds
	 *                       WordPress' own shipped default value.
	 */
	private function resolve_wp_auth_salts(): ?string {
		$constants    = array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY' );
		$concatenated = '';

		foreach ( $constants as $constant ) {
			if ( ! defined( $constant ) ) {
				return null;
			}

			$value = constant( $constant );

			if ( ! is_string( $value ) || '' === $value || str_contains( $value, self::WP_DEFAULT_SALT_PLACEHOLDER ) ) {
				return null;
			}

			$concatenated .= $value;
		}

		return hash( 'sha256', $concatenated, true );
	}
}
