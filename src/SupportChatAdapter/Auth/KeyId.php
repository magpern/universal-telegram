<?php
/**
 * ADR-0007 §3 key-ID format.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\SupportChatAdapter\Auth;

/**
 * `<sender-plugin-slug>.<16-lowercase-hex-chars>`, where the hex suffix is
 * the first 8 bytes of SHA-256(raw 32-byte Ed25519 public key), hex-encoded.
 * Mirrors Support Chat's own `KeyId` exactly (ADR-0007 §3), computed
 * independently here — this class is never copied from the Support Chat
 * repository.
 */
final class KeyId {

	/**
	 * Computes the key ID for a plugin slug and raw public key.
	 *
	 * @param string $plugin_slug    Plugin slug, e.g. "universal-telegram".
	 * @param string $raw_public_key Raw 32-byte Ed25519 public key.
	 */
	public static function compute( string $plugin_slug, string $raw_public_key ): string {
		$hash   = hash( 'sha256', $raw_public_key, true );
		$suffix = bin2hex( substr( $hash, 0, 8 ) );

		return $plugin_slug . '.' . $suffix;
	}

	/**
	 * Whether a value is a syntactically valid key ID.
	 *
	 * @param string $value Candidate key ID.
	 */
	public static function is_valid_format( string $value ): bool {
		return 1 === preg_match( '/^[a-z0-9-]+\.[0-9a-f]{16}$/', $value );
	}

	/**
	 * Not instantiable.
	 */
	private function __construct() {}
}
