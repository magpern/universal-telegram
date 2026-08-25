<?php
/**
 * Unit tests for ADR-0007 §3 outbound request signing.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Tests\SupportChatAdapter\Auth;

use PHPUnit\Framework\TestCase;
use UniversalTelegram\SupportChatAdapter\Auth\SignatureSigner;
use UniversalTelegram\SupportChatAdapter\ContractConstants;
use UniversalTelegram\Tests\SupportChatAdapter\Auth\Support\FakeOwnKeyManager;
use UniversalTelegram\Tests\SupportChatAdapter\Auth\Support\UnavailableOwnKeyManager;

/**
 * @covers \UniversalTelegram\SupportChatAdapter\Auth\SignatureSigner
 */
final class SignatureSignerTest extends TestCase {

	public function test_sign_produces_every_required_header(): void {
		$own    = new FakeOwnKeyManager();
		$signer = new SignatureSigner( $own );

		$headers = $signer->sign( 'post', '/universal-support-chat/v1/contract/claim', '{"a":1}', 'claim' );

		$this->assertIsArray( $headers );
		foreach (
			array(
				'X-SC-Contract-Version',
				'X-SC-Auth-Profile',
				'X-SC-Sender',
				'X-SC-Audience',
				'X-SC-Key-Id',
				'X-SC-Timestamp',
				'X-SC-Nonce',
				'X-SC-Body-Sha256',
				'X-SC-Signature',
			) as $name
		) {
			$this->assertArrayHasKey( $name, $headers );
			$this->assertNotSame( '', $headers[ $name ] );
		}

		$this->assertSame( ContractConstants::CONTRACT_VERSION_ID, $headers['X-SC-Contract-Version'] );
		$this->assertSame( ContractConstants::AUTH_PROFILE_ID, $headers['X-SC-Auth-Profile'] );
		$this->assertSame( ContractConstants::SELF_ID, $headers['X-SC-Sender'] );
		$this->assertSame( ContractConstants::PEER_ID, $headers['X-SC-Audience'] );
		$this->assertSame( hash( 'sha256', '{"a":1}' ), $headers['X-SC-Body-Sha256'] );
	}

	public function test_signature_verifies_against_the_exact_ten_line_canonical_string(): void {
		$own    = new FakeOwnKeyManager();
		$signer = new SignatureSigner( $own );

		$method   = 'POST';
		$path     = '/universal-support-chat/v1/contract/resolve';
		$raw_body = '{"channel_case_ref":"abc"}';

		$headers = $signer->sign( $method, $path, $raw_body, 'resolve' );
		$this->assertIsArray( $headers );

		$canonical = implode(
			"\n",
			array(
				ContractConstants::AUTH_PROFILE_ID,
				ContractConstants::CONTRACT_VERSION_ID,
				$headers['X-SC-Sender'],
				$headers['X-SC-Audience'],
				$headers['X-SC-Key-Id'],
				$headers['X-SC-Timestamp'],
				$headers['X-SC-Nonce'],
				$method,
				$path,
				$headers['X-SC-Body-Sha256'],
			)
		);

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- test assertion, not obfuscation.
		$signature = base64_decode( $headers['X-SC-Signature'], true );
		$this->assertIsString( $signature );
		$this->assertSame( 64, strlen( $signature ) );

		$this->assertTrue(
			sodium_crypto_sign_verify_detached( $signature, $canonical, $own->raw_public_key() )
		);
	}

	public function test_sign_fails_closed_when_own_key_unavailable(): void {
		$signer = new SignatureSigner( new UnavailableOwnKeyManager() );

		$headers = $signer->sign( 'POST', '/universal-support-chat/v1/contract/claim', '{}', 'claim' );

		$this->assertNull( $headers );
	}

	public function test_each_call_gets_a_fresh_nonce_and_timestamp_field(): void {
		$signer = new SignatureSigner( new FakeOwnKeyManager() );

		$first  = $signer->sign( 'POST', '/x', '{}', 'claim' );
		$second = $signer->sign( 'POST', '/x', '{}', 'claim' );

		$this->assertIsArray( $first );
		$this->assertIsArray( $second );
		$this->assertNotSame( $first['X-SC-Nonce'], $second['X-SC-Nonce'] );
	}
}
