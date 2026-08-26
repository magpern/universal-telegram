<?php
/**
 * Item 8: a valid paired request reaches the real UT handler without any
 * test-only authorization-filter bypass; the optional veto filter still
 * works when explicitly set to deny; and signature verification runs
 * first/independently of the filter. Exercised through the REAL
 * AdapterContractClient (cross-plugin), not a hand-built request — a
 * stronger proof than the local-fixture unit coverage already in
 * tests/integration/SupportChatAdapter/OutboundContractAuthorizationTest.php.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Tests\Integration\Interop;

final class AuthorizationOrderTest extends InteropTestCase {

	protected function tearDown(): void {
		remove_all_filters( 'universal_telegram_support_chat_adapter_rest_authorized' );
		parent::tearDown();
	}

	/** No test-only filter override anywhere: a real, paired, signed SC call reaches UT's real domain logic. */
	public function test_valid_paired_call_reaches_real_handler_without_filter_override(): void {
		self::assertFalse( has_filter( 'universal_telegram_support_chat_adapter_rest_authorized' ) );

		$conversation_uuid = wp_generate_uuid4();
		$result            = $this->sc_outbound_client->ensure_channel_case( 'universal-telegram', $conversation_uuid, 'escalated' );

		self::assertTrue( $result['ok'] );
		self::assertNotNull( $this->ut_bindings->find_by_conversation_uuid( $conversation_uuid ) );
	}

	/** The optional veto filter, when explicitly set false, still denies — proving it remains a working veto. */
	public function test_filter_set_false_vetoes_an_otherwise_valid_call(): void {
		add_filter( 'universal_telegram_support_chat_adapter_rest_authorized', '__return_false' );

		$conversation_uuid = wp_generate_uuid4();
		$result            = $this->sc_outbound_client->ensure_channel_case( 'universal-telegram', $conversation_uuid, 'escalated' );

		self::assertFalse( $result['ok'] );
		self::assertNull( $this->ut_bindings->find_by_conversation_uuid( $conversation_uuid ) );
	}

	/** Signature verification runs independently of, and before, the filter: even with the filter forced true, an invalid signature is still rejected. */
	public function test_invalid_signature_is_rejected_even_when_filter_forced_true(): void {
		add_filter( 'universal_telegram_support_chat_adapter_rest_authorized', '__return_true' );

		$conversation_uuid = wp_generate_uuid4();
		$sc_key            = ( new \UniversalSupportChat\ChannelContract\Auth\OwnKeyManager( new \UniversalSupportChat\Core\Security\CredentialVault() ) )->public_key();
		self::assertIsArray( $sc_key );

		$pair         = sodium_crypto_sign_keypair();
		$wrong_secret = sodium_crypto_sign_secretkey( $pair );

		$body      = array(
			'conversation_uuid' => $conversation_uuid,
			'idempotency_key'   => 'auth-order-badsig-1',
		);
		$raw_body  = (string) wp_json_encode( $body );
		$timestamp = (string) time();
		$nonce     = \UniversalTelegram\SupportChatAdapter\Auth\NonceGenerator::generate();
		$body_hash = hash( 'sha256', $raw_body );
		$route     = '/universal-telegram/v1/support-chat/ensure_channel_case';

		$canonical = implode(
			"\n",
			array(
				'support-channel-contract-auth/v1',
				'support-channel-contract/v1',
				'universal-support-chat',
				'universal-telegram',
				$sc_key['key_id'],
				$timestamp,
				$nonce,
				'POST',
				$route,
				$body_hash,
			)
		);
		$signature = sodium_crypto_sign_detached( $canonical, $wrong_secret );

		$request = new \WP_REST_Request( 'POST', $route );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-SC-Contract-Version', 'support-channel-contract/v1' );
		$request->set_header( 'X-SC-Auth-Profile', 'support-channel-contract-auth/v1' );
		$request->set_header( 'X-SC-Sender', 'universal-support-chat' );
		$request->set_header( 'X-SC-Audience', 'universal-telegram' );
		$request->set_header( 'X-SC-Key-Id', $sc_key['key_id'] );
		$request->set_header( 'X-SC-Timestamp', $timestamp );
		$request->set_header( 'X-SC-Nonce', $nonce );
		$request->set_header( 'X-SC-Body-Sha256', $body_hash );
		$request->set_header( 'X-SC-Signature', base64_encode( $signature ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- test fixture.
		$request->set_body( $raw_body );

		$response = rest_do_request( $request );

		self::assertTrue( $response->is_error() || $response->get_status() >= 400, 'An invalid signature must still be rejected even though the optional veto filter is forced true.' );
		self::assertNull( $this->ut_bindings->find_by_conversation_uuid( $conversation_uuid ) );
	}
}
