<?php
/**
 * ADR-0007 §3 nonce generation.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\SupportChatAdapter\Auth;

/**
 * Produces a per-request nonce: 16 raw random bytes, encoded as unpadded
 * base64url (22 characters) — ADR-0007 §3's exact format.
 */
final class NonceGenerator {

	/**
	 * Generates a fresh nonce.
	 */
	public static function generate(): string {
		$raw = random_bytes( 16 );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- transport encoding, not obfuscation.
		$standard = base64_encode( $raw );

		return rtrim( strtr( $standard, '+/', '-_' ), '=' );
	}

	/**
	 * Whether a value is well-formed unpadded base64url of 16 raw bytes.
	 *
	 * @param string $value Candidate nonce.
	 */
	public static function is_valid_format( string $value ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9_-]{22}$/', $value );
	}

	/**
	 * Not instantiable.
	 */
	private function __construct() {}
}
