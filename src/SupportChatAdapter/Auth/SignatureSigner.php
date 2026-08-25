<?php
/**
 * Contract v1 outbound request signing (ADR-0007 §3).
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\SupportChatAdapter\Auth;

use UniversalTelegram\SupportChatAdapter\ContractConstants;

/**
 * Builds ADR-0007 §3's exact ten-line canonical string and Ed25519
 * signature for one outbound adapter → Support Chat request, and returns
 * the exact header set to attach. Never logs or otherwise exposes the
 * private key, the raw signature, or the nonce beyond the returned headers.
 */
class SignatureSigner {

	/**
	 * Constructor.
	 *
	 * @param OwnKeyManager $own_key This plugin's own key pair.
	 */
	public function __construct( private readonly OwnKeyManager $own_key ) {}

	/**
	 * Signs one outbound request. Returns null (fail closed) if this
	 * plugin's own key pair or libsodium is unavailable — never signs with
	 * a placeholder or otherwise degrades silently.
	 *
	 * @param string $method    Uppercase HTTP method.
	 * @param string $path      Canonical route path (no query string).
	 * @param string $raw_body  Exact raw request body bytes to be sent.
	 * @param string $operation The Contract operation this request targets.
	 *
	 * @return array<string, string>|null Header name => value map, or null.
	 */
	public function sign( string $method, string $path, string $raw_body, string $operation ): ?array {
		unset( $operation );

		if ( ! function_exists( 'sodium_crypto_sign_detached' ) ) {
			return null;
		}

		$own    = $this->own_key->public_key();
		$secret = $this->own_key->secret_key_raw();

		if ( null === $own || null === $secret ) {
			return null;
		}

		$timestamp = (string) time();
		$nonce     = NonceGenerator::generate();
		$body_hash = hash( 'sha256', $raw_body );
		$key_id    = $own['key_id'];
		$canonical = $this->canonical_string( $key_id, $timestamp, $nonce, strtoupper( $method ), $path, $body_hash );
		$signature = sodium_crypto_sign_detached( $canonical, $secret );

		return array(
			'X-SC-Contract-Version' => ContractConstants::CONTRACT_VERSION_ID,
			'X-SC-Auth-Profile'     => ContractConstants::AUTH_PROFILE_ID,
			'X-SC-Sender'           => ContractConstants::SELF_ID,
			'X-SC-Audience'         => ContractConstants::PEER_ID,
			'X-SC-Key-Id'           => $key_id,
			'X-SC-Timestamp'        => $timestamp,
			'X-SC-Nonce'            => $nonce,
			'X-SC-Body-Sha256'      => $body_hash,
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- transport encoding, not obfuscation.
			'X-SC-Signature'        => base64_encode( $signature ),
		);
	}

	/**
	 * Builds ADR-0007 §3's exact ten-line canonical string.
	 *
	 * @param string $key_id    This plugin's current key ID.
	 * @param string $timestamp Unix seconds, decimal ASCII.
	 * @param string $nonce     Per-request nonce.
	 * @param string $method    Uppercase HTTP method.
	 * @param string $path      Canonical route path.
	 * @param string $body_hash Lowercase hex SHA-256 of the raw request body.
	 */
	private function canonical_string( string $key_id, string $timestamp, string $nonce, string $method, string $path, string $body_hash ): string {
		return implode(
			"\n",
			array(
				ContractConstants::AUTH_PROFILE_ID,
				ContractConstants::CONTRACT_VERSION_ID,
				ContractConstants::SELF_ID,
				ContractConstants::PEER_ID,
				$key_id,
				$timestamp,
				$nonce,
				$method,
				$path,
				$body_hash,
			)
		);
	}
}
