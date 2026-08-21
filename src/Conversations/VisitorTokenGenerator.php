<?php
/**
 * Visitor conversation credential generation and verification.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Conversations;

/**
 * Generates the two-part visitor credential (M05 plan §3, docs/adr/0021):
 * a public `conversation_uuid` and a private 256-bit bearer secret. Only
 * the secret's password_hash() is ever meant to be persisted; this class
 * never persists anything itself. Verification is password_verify() only —
 * it is internally timing-safe on its own, so no hash_equals() call exists
 * anywhere in this protocol.
 */
final class VisitorTokenGenerator {

	/**
	 * Generates a fresh conversation_uuid and bearer secret, and the secret's
	 * one-way hash for storage. The plaintext secret is returned exactly
	 * once, by the caller's own start-response path — this class holds no
	 * state and does not persist it.
	 *
	 * @return array{conversation_uuid: string, secret: string, secret_hash: string}
	 */
	public function generate(): array {
		$secret = bin2hex( random_bytes( 32 ) );

		return array(
			'conversation_uuid' => wp_generate_uuid4(),
			'secret'            => $secret,
			'secret_hash'       => password_hash( $secret, PASSWORD_DEFAULT ),
		);
	}

	/**
	 * Verifies a presented secret against a stored hash.
	 * password_verify() is internally timing-safe.
	 *
	 * @param string $presented_secret The secret presented by the caller.
	 * @param string $secret_hash      The stored password_hash() value.
	 *
	 * @return bool
	 */
	public function verify( string $presented_secret, string $secret_hash ): bool {
		return password_verify( $presented_secret, $secret_hash );
	}
}
