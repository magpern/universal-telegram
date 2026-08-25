<?php
/**
 * Contract v1 signature verification (ADR-0007 §3-§4).
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\SupportChatAdapter\Auth;

use UniversalTelegram\SupportChatAdapter\ContractConstants;

/**
 * Verifies one authenticated Support Chat → adapter Contract v1 request
 * against ADR-0007's exact ten-line canonical string. A pure,
 * HTTP-framework-agnostic class: the caller extracts headers/method/path/
 * body from its own request object. This class is UT's own implementation
 * of the mirror-image check Support Chat's `SignatureVerifier` performs —
 * it is not copied from that repository.
 */
class SignatureVerifier {

	private const TIMESTAMP_WINDOW_SECONDS = 300;

	private const REQUIRED_HEADERS = array(
		'contract_version',
		'auth_profile',
		'sender',
		'audience',
		'key_id',
		'timestamp',
		'nonce',
		'body_sha256',
		'signature',
	);

	/**
	 * Constructor.
	 *
	 * @param PeerRepository        $peers  Peer key store.
	 * @param NonceReplayRepository $nonces Nonce replay store.
	 */
	public function __construct(
		private readonly PeerRepository $peers,
		private readonly NonceReplayRepository $nonces
	) {}

	/**
	 * Verifies one request.
	 *
	 * @param string                $method           Uppercase HTTP method.
	 * @param string                $path             Canonical route path (no query string).
	 * @param string                $raw_body         Exact raw request body bytes.
	 * @param array<string, string> $headers          Normalized headers: contract_version, auth_profile,
	 *                                                 sender, audience, key_id, timestamp, nonce,
	 *                                                 body_sha256, signature — only keys actually present.
	 * @param string                $operation        The Contract operation this request targets.
	 * @param bool                  $has_query_params Whether the request carried any query parameter.
	 */
	public function verify(
		string $method,
		string $path,
		string $raw_body,
		array $headers,
		string $operation,
		bool $has_query_params
	): VerificationResult {
		if ( $has_query_params ) {
			return VerificationResult::denied();
		}

		foreach ( self::REQUIRED_HEADERS as $name ) {
			if ( ! isset( $headers[ $name ] ) || '' === $headers[ $name ] ) {
				return VerificationResult::denied();
			}
		}

		if ( ContractConstants::CONTRACT_VERSION_ID !== $headers['contract_version'] ) {
			return VerificationResult::denied();
		}

		if ( ContractConstants::AUTH_PROFILE_ID !== $headers['auth_profile'] ) {
			return VerificationResult::denied();
		}

		if ( ContractConstants::SELF_ID !== $headers['audience'] ) {
			return VerificationResult::denied();
		}

		if ( ! in_array( $operation, ContractConstants::support_chat_to_adapter_operations(), true ) ) {
			return VerificationResult::denied();
		}

		$sender = $headers['sender'];
		$peer   = $this->peers->find_by_peer_id( $sender );

		if ( null === $peer || ! $peer->is_usable() ) {
			return VerificationResult::denied();
		}

		if ( ! hash_equals( $peer->key_id(), $headers['key_id'] ) ) {
			return VerificationResult::denied();
		}

		if ( ! $peer->allows( $operation ) ) {
			return VerificationResult::denied();
		}

		if ( ! $this->timestamp_within_window( $headers['timestamp'] ) ) {
			return VerificationResult::denied();
		}

		if ( ! NonceGenerator::is_valid_format( $headers['nonce'] ) ) {
			return VerificationResult::denied();
		}

		$expected_body_hash = hash( 'sha256', $raw_body );
		if ( ! hash_equals( $expected_body_hash, strtolower( $headers['body_sha256'] ) ) ) {
			return VerificationResult::denied();
		}

		$public_key = $peer->public_key_raw();
		if ( null === $public_key ) {
			return VerificationResult::denied();
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- transport decoding, not obfuscation.
		$signature = base64_decode( $headers['signature'], true );
		if ( false === $signature || 64 !== strlen( $signature ) ) {
			return VerificationResult::denied();
		}

		$canonical = $this->canonical_string( $headers, $method, $path );

		if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			return VerificationResult::denied();
		}

		$signature_valid = sodium_crypto_sign_verify_detached( $signature, $canonical, $public_key );
		if ( ! $signature_valid ) {
			return VerificationResult::denied();
		}

		// Signature valid — now atomically claim the nonce. A duplicate
		// tuple at this point is a genuine replay, never a false positive
		// from an earlier failed/forged attempt (those never reach here).
		if ( ! $this->nonces->record_if_new( $sender, $headers['key_id'], $headers['nonce'] ) ) {
			return VerificationResult::denied();
		}

		$this->peers->touch_last_used( $sender );

		return VerificationResult::accepted( $sender );
	}

	/**
	 * Whether the timestamp is numeric and within the acceptance window.
	 *
	 * @param string $timestamp Raw timestamp header value.
	 */
	private function timestamp_within_window( string $timestamp ): bool {
		if ( 1 !== preg_match( '/^\d{1,10}$/', $timestamp ) ) {
			return false;
		}

		$delta = abs( time() - (int) $timestamp );

		return $delta <= self::TIMESTAMP_WINDOW_SECONDS;
	}

	/**
	 * Builds ADR-0007 §3's exact ten-line canonical string.
	 *
	 * @param array<string, string> $headers Normalized headers.
	 * @param string                $method  Uppercase HTTP method.
	 * @param string                $path    Canonical route path.
	 */
	private function canonical_string( array $headers, string $method, string $path ): string {
		return implode(
			"\n",
			array(
				ContractConstants::AUTH_PROFILE_ID,
				ContractConstants::CONTRACT_VERSION_ID,
				$headers['sender'],
				$headers['audience'],
				$headers['key_id'],
				$headers['timestamp'],
				$headers['nonce'],
				$method,
				$path,
				$headers['body_sha256'],
			)
		);
	}
}
