<?php
/**
 * Unit tests for ADR-0007 §3-§4 inbound signature verification.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Tests\SupportChatAdapter\Auth;

use PHPUnit\Framework\TestCase;
use UniversalTelegram\SupportChatAdapter\Auth\KeyId;
use UniversalTelegram\SupportChatAdapter\Auth\NonceGenerator;
use UniversalTelegram\SupportChatAdapter\Auth\PeerRecord;
use UniversalTelegram\SupportChatAdapter\Auth\SignatureVerifier;
use UniversalTelegram\SupportChatAdapter\ContractConstants;
use UniversalTelegram\Tests\SupportChatAdapter\Auth\Support\FakeNonceReplayRepository;
use UniversalTelegram\Tests\SupportChatAdapter\Auth\Support\FakePeerRepository;

/**
 * @covers \UniversalTelegram\SupportChatAdapter\Auth\SignatureVerifier
 */
final class SignatureVerifierTest extends TestCase {

	private const METHOD    = 'POST';
	private const PATH      = '/universal-telegram/v1/support-chat/ensure_channel_case';
	private const OPERATION = 'ensure_channel_case';

	private string $public_raw;

	private string $secret_raw;

	private string $key_id;

	private FakePeerRepository $peers;

	private FakeNonceReplayRepository $nonces;

	private SignatureVerifier $verifier;

	protected function setUp(): void {
		parent::setUp();

		$pair             = sodium_crypto_sign_keypair();
		$this->public_raw = sodium_crypto_sign_publickey( $pair );
		$this->secret_raw = sodium_crypto_sign_secretkey( $pair );
		$this->key_id     = KeyId::compute( ContractConstants::PEER_ID, $this->public_raw );

		$this->peers  = new FakePeerRepository();
		$this->nonces = new FakeNonceReplayRepository();

		$this->peers->seed(
			ContractConstants::PEER_ID,
			new PeerRecord(
				1,
				ContractConstants::PEER_ID,
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- test fixture, not obfuscation.
				base64_encode( $this->public_raw ),
				$this->key_id,
				array( self::OPERATION ),
				null,
				PeerRecord::STATUS_ACTIVE,
				gmdate( 'Y-m-d H:i:s' ),
				null,
				null,
				null,
				null
			)
		);

		$this->verifier = new SignatureVerifier( $this->peers, $this->nonces );
	}

	/**
	 * Builds a well-formed, correctly signed Support Chat -> UT request.
	 * Any single $overrides key replaces that field before signing (for
	 * tamper tests) or is applied to the header map after signing.
	 *
	 * @param array<string, mixed> $overrides Field overrides applied before or after signing.
	 *
	 * @return array{0: string, 1: array<string, string>}
	 */
	private function build_request( array $overrides = array() ): array {
		$raw_body  = $overrides['raw_body'] ?? '{"conversation_uuid":"c-1","idempotency_key":"k-1"}';
		$timestamp = $overrides['timestamp'] ?? (string) time();
		$nonce     = $overrides['nonce'] ?? NonceGenerator::generate();
		$sender    = $overrides['sender'] ?? ContractConstants::PEER_ID;
		$audience  = $overrides['audience'] ?? ContractConstants::SELF_ID;
		$key_id    = $overrides['key_id'] ?? $this->key_id;
		$path      = $overrides['path'] ?? self::PATH;
		$method    = $overrides['method'] ?? self::METHOD;
		$secret    = $overrides['secret'] ?? $this->secret_raw;

		$body_hash = hash( 'sha256', (string) $raw_body );

		$canonical = implode(
			"\n",
			array(
				ContractConstants::AUTH_PROFILE_ID,
				ContractConstants::CONTRACT_VERSION_ID,
				$sender,
				$audience,
				$key_id,
				$timestamp,
				$nonce,
				$method,
				$path,
				$body_hash,
			)
		);

		$signature = sodium_crypto_sign_detached( $canonical, $secret );

		$headers = array(
			'contract_version' => ContractConstants::CONTRACT_VERSION_ID,
			'auth_profile'     => ContractConstants::AUTH_PROFILE_ID,
			'sender'           => $sender,
			'audience'         => $audience,
			'key_id'           => $key_id,
			'timestamp'        => $timestamp,
			'nonce'            => $nonce,
			'body_sha256'      => $body_hash,
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- test fixture, not obfuscation.
			'signature'        => base64_encode( $signature ),
		);

		foreach ( $overrides as $key => $value ) {
			if ( array_key_exists( $key, $headers ) ) {
				$headers[ $key ] = $value;
			}
		}

		return array( (string) $raw_body, $headers );
	}

	public function test_valid_signed_request_is_accepted(): void {
		list( $raw_body, $headers ) = $this->build_request();

		$result = $this->verifier->verify( self::METHOD, self::PATH, $raw_body, $headers, self::OPERATION, false );

		$this->assertTrue( $result->ok() );
		$this->assertSame( ContractConstants::PEER_ID, $result->peer_id() );
		$this->assertSame( array( ContractConstants::PEER_ID ), $this->peers->touched );
	}

	public function test_query_string_present_is_rejected(): void {
		list( $raw_body, $headers ) = $this->build_request();

		$result = $this->verifier->verify( self::METHOD, self::PATH, $raw_body, $headers, self::OPERATION, true );

		$this->assertFalse( $result->ok() );
	}

	public function test_missing_header_is_rejected(): void {
		list( $raw_body, $headers ) = $this->build_request();
		unset( $headers['nonce'] );

		$result = $this->verifier->verify( self::METHOD, self::PATH, $raw_body, $headers, self::OPERATION, false );

		$this->assertFalse( $result->ok() );
	}

	public function test_wrong_contract_version_is_rejected(): void {
		list( $raw_body, $headers )  = $this->build_request();
		$headers['contract_version'] = 'support-channel-contract/v2';

		$result = $this->verifier->verify( self::METHOD, self::PATH, $raw_body, $headers, self::OPERATION, false );

		$this->assertFalse( $result->ok() );
	}

	public function test_wrong_auth_profile_is_rejected(): void {
		list( $raw_body, $headers ) = $this->build_request();
		$headers['auth_profile']    = 'something-else/v1';

		$result = $this->verifier->verify( self::METHOD, self::PATH, $raw_body, $headers, self::OPERATION, false );

		$this->assertFalse( $result->ok() );
	}

	public function test_wrong_audience_is_rejected(): void {
		list( $raw_body, $headers ) = $this->build_request( array( 'audience' => 'some-other-plugin' ) );

		$result = $this->verifier->verify( self::METHOD, self::PATH, $raw_body, $headers, self::OPERATION, false );

		$this->assertFalse( $result->ok() );
	}

	public function test_wrong_sender_is_rejected_as_unknown_peer(): void {
		list( $raw_body, $headers ) = $this->build_request( array( 'sender' => 'some-other-plugin' ) );

		$result = $this->verifier->verify( self::METHOD, self::PATH, $raw_body, $headers, self::OPERATION, false );

		$this->assertFalse( $result->ok() );
	}

	public function test_unsupported_operation_is_rejected(): void {
		list( $raw_body, $headers ) = $this->build_request();

		$result = $this->verifier->verify( self::METHOD, self::PATH, $raw_body, $headers, 'update_operator_presence', false );

		$this->assertFalse( $result->ok() );
	}

	public function test_operation_not_on_peer_allow_list_is_rejected(): void {
		list( $raw_body, $headers ) = $this->build_request();

		// Peer is only seeded with 'ensure_channel_case'.
		$result = $this->verifier->verify( self::METHOD, self::PATH, $raw_body, $headers, 'deliver_message', false );

		$this->assertFalse( $result->ok() );
	}

	public function test_unknown_key_id_is_rejected(): void {
		list( $raw_body, $headers ) = $this->build_request( array( 'key_id' => 'universal-support-chat.0000000000000000' ) );

		$result = $this->verifier->verify( self::METHOD, self::PATH, $raw_body, $headers, self::OPERATION, false );

		$this->assertFalse( $result->ok() );
	}

	public function test_revoked_peer_is_rejected(): void {
		$this->peers->seed(
			ContractConstants::PEER_ID,
			new PeerRecord(
				1,
				ContractConstants::PEER_ID,
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- test fixture, not obfuscation.
				base64_encode( $this->public_raw ),
				$this->key_id,
				array( self::OPERATION ),
				null,
				PeerRecord::STATUS_REVOKED,
				gmdate( 'Y-m-d H:i:s' ),
				null,
				null,
				null,
				gmdate( 'Y-m-d H:i:s' )
			)
		);

		list( $raw_body, $headers ) = $this->build_request();

		$result = $this->verifier->verify( self::METHOD, self::PATH, $raw_body, $headers, self::OPERATION, false );

		$this->assertFalse( $result->ok() );
	}

	public function test_disabled_peer_is_rejected(): void {
		$this->peers->seed(
			ContractConstants::PEER_ID,
			new PeerRecord(
				1,
				ContractConstants::PEER_ID,
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- test fixture, not obfuscation.
				base64_encode( $this->public_raw ),
				$this->key_id,
				array( self::OPERATION ),
				null,
				PeerRecord::STATUS_DISABLED,
				gmdate( 'Y-m-d H:i:s' ),
				null,
				null,
				null,
				null
			)
		);

		list( $raw_body, $headers ) = $this->build_request();

		$result = $this->verifier->verify( self::METHOD, self::PATH, $raw_body, $headers, self::OPERATION, false );

		$this->assertFalse( $result->ok() );
	}

	public function test_expired_peer_is_rejected(): void {
		$this->peers->seed(
			ContractConstants::PEER_ID,
			new PeerRecord(
				1,
				ContractConstants::PEER_ID,
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- test fixture, not obfuscation.
				base64_encode( $this->public_raw ),
				$this->key_id,
				array( self::OPERATION ),
				null,
				PeerRecord::STATUS_ACTIVE,
				gmdate( 'Y-m-d H:i:s', time() - 86400 ),
				null,
				null,
				gmdate( 'Y-m-d H:i:s', time() - 3600 ),
				null
			)
		);

		list( $raw_body, $headers ) = $this->build_request();

		$result = $this->verifier->verify( self::METHOD, self::PATH, $raw_body, $headers, self::OPERATION, false );

		$this->assertFalse( $result->ok() );
	}

	public function test_invalid_signature_is_rejected(): void {
		list( $raw_body, $headers ) = $this->build_request();
		$headers['signature']       = str_repeat( 'A', 88 );

		$result = $this->verifier->verify( self::METHOD, self::PATH, $raw_body, $headers, self::OPERATION, false );

		$this->assertFalse( $result->ok() );
	}

	public function test_signature_from_wrong_key_is_rejected(): void {
		$other_pair = sodium_crypto_sign_keypair();

		list( $raw_body, $headers ) = $this->build_request( array( 'secret' => sodium_crypto_sign_secretkey( $other_pair ) ) );

		$result = $this->verifier->verify( self::METHOD, self::PATH, $raw_body, $headers, self::OPERATION, false );

		$this->assertFalse( $result->ok() );
	}

	public function test_body_tamper_after_signing_is_rejected(): void {
		list( $raw_body, $headers ) = $this->build_request();
		unset( $raw_body );

		$tampered_body = '{"conversation_uuid":"c-1","idempotency_key":"k-1","extra":"tampered"}';

		$result = $this->verifier->verify( self::METHOD, self::PATH, $tampered_body, $headers, self::OPERATION, false );

		$this->assertFalse( $result->ok() );
	}

	public function test_stale_timestamp_is_rejected(): void {
		list( $raw_body, $headers ) = $this->build_request( array( 'timestamp' => (string) ( time() - 301 ) ) );

		$result = $this->verifier->verify( self::METHOD, self::PATH, $raw_body, $headers, self::OPERATION, false );

		$this->assertFalse( $result->ok() );
	}

	public function test_future_timestamp_beyond_window_is_rejected(): void {
		list( $raw_body, $headers ) = $this->build_request( array( 'timestamp' => (string) ( time() + 301 ) ) );

		$result = $this->verifier->verify( self::METHOD, self::PATH, $raw_body, $headers, self::OPERATION, false );

		$this->assertFalse( $result->ok() );
	}

	public function test_malformed_timestamp_is_rejected(): void {
		list( $raw_body, $headers ) = $this->build_request( array( 'timestamp' => 'not-a-number' ) );

		$result = $this->verifier->verify( self::METHOD, self::PATH, $raw_body, $headers, self::OPERATION, false );

		$this->assertFalse( $result->ok() );
	}

	public function test_nonce_replay_is_rejected(): void {
		list( $raw_body, $headers ) = $this->build_request();

		$first  = $this->verifier->verify( self::METHOD, self::PATH, $raw_body, $headers, self::OPERATION, false );
		$second = $this->verifier->verify( self::METHOD, self::PATH, $raw_body, $headers, self::OPERATION, false );

		$this->assertTrue( $first->ok() );
		$this->assertFalse( $second->ok() );
	}

	public function test_different_route_path_invalidates_signature(): void {
		list( $raw_body, $headers ) = $this->build_request();

		$result = $this->verifier->verify( self::METHOD, '/universal-telegram/v1/support-chat/deliver_message', $raw_body, $headers, self::OPERATION, false );

		$this->assertFalse( $result->ok() );
	}

	public function test_different_method_invalidates_signature(): void {
		list( $raw_body, $headers ) = $this->build_request();

		$result = $this->verifier->verify( 'GET', self::PATH, $raw_body, $headers, self::OPERATION, false );

		$this->assertFalse( $result->ok() );
	}
}
