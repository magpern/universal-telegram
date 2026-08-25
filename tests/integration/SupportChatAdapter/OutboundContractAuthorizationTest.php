<?php
/**
 * Integration tests for outbound Contract acceptor authorization.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Tests\Integration\SupportChatAdapter;

use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;
use UniversalTelegram\Core\Configuration\Settings;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Queue\Dispatcher;
use UniversalTelegram\SupportChatAdapter\Auth\KeyId;
use UniversalTelegram\SupportChatAdapter\Auth\NonceGenerator;
use UniversalTelegram\SupportChatAdapter\Auth\NonceReplayRepository;
use UniversalTelegram\SupportChatAdapter\Auth\PeerRepository;
use UniversalTelegram\SupportChatAdapter\Auth\SignatureVerifier;
use UniversalTelegram\SupportChatAdapter\ChannelBindingRepository;
use UniversalTelegram\SupportChatAdapter\ContractConstants;
use UniversalTelegram\SupportChatAdapter\DeliveryIdempotencyRepository;
use UniversalTelegram\SupportChatAdapter\DiscoveryClient;
use UniversalTelegram\SupportChatAdapter\Outbound\BackfillService;
use UniversalTelegram\SupportChatAdapter\Outbound\DeliverMessageService;
use UniversalTelegram\SupportChatAdapter\Outbound\EnsureChannelCaseService;
use UniversalTelegram\SupportChatAdapter\Outbound\NotifyOperatorsService;
use UniversalTelegram\SupportChatAdapter\Outbound\OutboundContractController;
use UniversalTelegram\Telegram\Client\TelegramApiClient;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;
use UniversalTelegram\Telegram\Configuration\DestinationRepository;
use UniversalTelegram\Telegram\Outbound\OutboundMessageRepository;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * @covers \UniversalTelegram\SupportChatAdapter\Outbound\OutboundContractController
 */
final class OutboundContractAuthorizationTest extends WP_UnitTestCase {

	private OutboundContractController $controller;

	private ChannelBindingRepository $bindings;

	private PeerRepository $peers;

	private string $sc_public_raw;

	private string $sc_secret_raw;

	private string $sc_key_id;

	protected function setUp(): void {
		parent::setUp();

		$schema         = new SchemaHealth();
		$settings       = new Settings();
		$this->bindings = new ChannelBindingRepository( $schema );
		$delivery       = new DeliveryIdempotencyRepository( $schema );
		$bots           = new BotProfileRepository( $schema, new CredentialVault() );
		$destinations   = new DestinationRepository( $schema );
		$messages       = new OutboundMessageRepository( $schema, new CredentialVault() );
		$dispatcher     = new Dispatcher( $schema );

		$ensure   = new EnsureChannelCaseService( $this->bindings, $bots, $destinations, new TelegramApiClient() );
		$deliver  = new DeliverMessageService( $this->bindings, $delivery, $messages, $dispatcher );
		$notify   = new NotifyOperatorsService( $deliver );
		$backfill = new BackfillService( $deliver );

		$this->peers = new PeerRepository( $schema );
		$nonces      = new NonceReplayRepository( $schema );
		$verifier    = new SignatureVerifier( $this->peers, $nonces );

		$this->controller = new OutboundContractController(
			new DiscoveryClient(),
			$settings,
			$destinations,
			$ensure,
			$notify,
			$backfill,
			$deliver,
			$verifier
		);
		add_action( 'rest_api_init', array( $this->controller, 'register_routes' ) );
		// A local fixture standing in for SC-M03's discovery route, so
		// authorize_mutation()'s pre-existing Compatible-discovery leg can
		// be satisfied inside these tests without a live Support Chat
		// plugin (plan v2 §7's "local fixture/mock Support Chat Contract
		// server").
		add_action(
			'rest_api_init',
			static function (): void {
				register_rest_route(
					'universal-support-chat/v1',
					'/channel-contract',
					array(
						'methods'             => 'GET',
						'callback'            => static fn () => new \WP_REST_Response(
							array(
								'ok'                => true,
								'contract_version'  => ContractConstants::CONTRACT_VERSION_ID,
								'auth_profile'      => ContractConstants::AUTH_PROFILE_ID,
								'adapter_required'  => false,
								'channel_available' => true,
								'operations'        => ContractConstants::adapter_to_support_chat_operations(),
							),
							200
						),
						'permission_callback' => '__return_true',
					)
				);
			}
		);
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core REST bootstrap hook used to register routes in tests.
		do_action( 'rest_api_init' );

		update_option(
			Settings::OPTION_NAME,
			array_merge(
				$settings->get(),
				array(
					'support_chat_adapter_enabled' => true,
				)
			)
		);

		// Clear any prior auth filter from other tests.
		remove_all_filters( 'universal_telegram_support_chat_adapter_rest_authorized' );

		$pair                = sodium_crypto_sign_keypair();
		$this->sc_public_raw = sodium_crypto_sign_publickey( $pair );
		$this->sc_secret_raw = sodium_crypto_sign_secretkey( $pair );
		$this->sc_key_id     = KeyId::compute( ContractConstants::PEER_ID, $this->sc_public_raw );
	}

	/**
	 * Pairs a real, usable Support Chat peer with the given allow-list.
	 *
	 * @param array<int, string> $allowed_operations Permitted operations.
	 */
	private function pair_peer( array $allowed_operations ): void {
		$this->peers->create(
			ContractConstants::PEER_ID,
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- test fixture, not obfuscation.
			base64_encode( $this->sc_public_raw ),
			$this->sc_key_id,
			$allowed_operations,
			ContractConstants::SUPPORT_CHAT_MANAGE_CAPABILITY
		);
	}

	/**
	 * Builds a WP_REST_Request signed exactly as Support Chat would sign a
	 * call to this adapter (ADR-0007 §3), against this test's paired key.
	 *
	 * @param string               $operation Contract v1 operation.
	 * @param string               $route     Registered UT route (leading slash).
	 * @param array<string, mixed> $body      Request body.
	 */
	private function build_signed_request( string $operation, string $route, array $body ): WP_REST_Request {
		unset( $operation );

		$raw_body  = (string) wp_json_encode( $body );
		$timestamp = (string) time();
		$nonce     = NonceGenerator::generate();
		$body_hash = hash( 'sha256', $raw_body );

		$canonical = implode(
			"\n",
			array(
				ContractConstants::AUTH_PROFILE_ID,
				ContractConstants::CONTRACT_VERSION_ID,
				ContractConstants::PEER_ID,
				ContractConstants::SELF_ID,
				$this->sc_key_id,
				$timestamp,
				$nonce,
				'POST',
				$route,
				$body_hash,
			)
		);

		$signature = sodium_crypto_sign_detached( $canonical, $this->sc_secret_raw );

		$request = new WP_REST_Request( 'POST', $route );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-SC-Contract-Version', ContractConstants::CONTRACT_VERSION_ID );
		$request->set_header( 'X-SC-Auth-Profile', ContractConstants::AUTH_PROFILE_ID );
		$request->set_header( 'X-SC-Sender', ContractConstants::PEER_ID );
		$request->set_header( 'X-SC-Audience', ContractConstants::SELF_ID );
		$request->set_header( 'X-SC-Key-Id', $this->sc_key_id );
		$request->set_header( 'X-SC-Timestamp', $timestamp );
		$request->set_header( 'X-SC-Nonce', $nonce );
		$request->set_header( 'X-SC-Body-Sha256', $body_hash );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- test fixture, not obfuscation.
		$request->set_header( 'X-SC-Signature', base64_encode( $signature ) );
		$request->set_body( $raw_body );

		return $request;
	}

	public function test_unauthenticated_ensure_is_rejected_and_creates_no_binding(): void {
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'POST', '/' . ContractConstants::UT_REST_NAMESPACE . ContractConstants::UT_REST_PREFIX . '/ensure_channel_case' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'conversation_uuid' => 'cccccccc-cccc-cccc-cccc-cccccccccccc',
					'idempotency_key'   => 'ensure-unauth-1',
				)
			)
		);

		$response = rest_do_request( $request );
		$this->assertTrue( $response->is_error() || $response->get_status() >= 400 );
		$this->assertNull( $this->bindings->find_by_conversation_uuid( 'cccccccc-cccc-cccc-cccc-cccccccccccc' ) );
	}

	public function test_support_chat_manage_alone_cannot_ensure_or_deliver(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user    = get_user_by( 'id', $user_id );
		$this->assertInstanceOf( \WP_User::class, $user );
		$user->add_cap( 'universal_support_chat_manage' );
		wp_set_current_user( $user_id );

		$ensure = new WP_REST_Request( 'POST', '/' . ContractConstants::UT_REST_NAMESPACE . ContractConstants::UT_REST_PREFIX . '/ensure_channel_case' );
		$ensure->set_header( 'Content-Type', 'application/json' );
		$ensure->set_body(
			wp_json_encode(
				array(
					'conversation_uuid' => 'dddddddd-dddd-dddd-dddd-dddddddddddd',
					'idempotency_key'   => 'ensure-sc-manage-1',
				)
			)
		);
		$ensure_response = rest_do_request( $ensure );
		$this->assertTrue( $ensure_response->is_error() || $ensure_response->get_status() >= 400 );
		$this->assertNull( $this->bindings->find_by_conversation_uuid( 'dddddddd-dddd-dddd-dddd-dddddddddddd' ) );

		$deliver = new WP_REST_Request( 'POST', '/' . ContractConstants::UT_REST_NAMESPACE . ContractConstants::UT_REST_PREFIX . '/deliver_message' );
		$deliver->set_header( 'Content-Type', 'application/json' );
		$deliver->set_body(
			wp_json_encode(
				array(
					'channel_case_ref' => 'eeeeeeee-eeee-eeee-eeee-eeeeeeeeeeee',
					'idempotency_key'  => 'deliver-sc-manage-1',
					'body'             => 'should not enqueue',
				)
			)
		);
		$deliver_response = rest_do_request( $deliver );
		$this->assertTrue( $deliver_response->is_error() || $deliver_response->get_status() >= 400 );
	}

	public function test_ut_manage_alone_cannot_mutate_while_contract_unavailable(): void {
		( new CapabilityRegistrar() )->grant_to_administrator();
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$request = new WP_REST_Request( 'POST', '/' . ContractConstants::UT_REST_NAMESPACE . ContractConstants::UT_REST_PREFIX . '/ensure_channel_case' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'conversation_uuid' => 'ffffffff-ffff-ffff-ffff-ffffffffffff',
					'idempotency_key'   => 'ensure-ut-manage-1',
				)
			)
		);

		$response = rest_do_request( $request );
		// Permission fails (filter default false / discovery not Compatible) —
		// never creates a binding or Telegram topic.
		$this->assertTrue( $response->is_error() || $response->get_status() >= 400 );
		$this->assertNull( $this->bindings->find_by_conversation_uuid( 'ffffffff-ffff-ffff-ffff-ffffffffffff' ) );
	}

	public function test_valid_signature_alone_is_rejected_while_the_default_deny_filter_stays_false(): void {
		wp_set_current_user( 0 );
		$this->pair_peer( array( 'ensure_channel_case' ) );

		$route   = '/' . ContractConstants::UT_REST_NAMESPACE . ContractConstants::UT_REST_PREFIX . '/ensure_channel_case';
		$body    = array(
			'conversation_uuid' => 'a1111111-1111-1111-1111-111111111111',
			'idempotency_key'   => 'ensure-sig-only-1',
		);
		$request = $this->build_signed_request( 'ensure_channel_case', $route, $body );

		// The default-deny filter is left at its default false — ADR-0038
		// requires BOTH gates, never signature alone.
		$response = rest_do_request( $request );

		$this->assertTrue( $response->is_error() || $response->get_status() >= 400 );
		$this->assertNull( $this->bindings->find_by_conversation_uuid( 'a1111111-1111-1111-1111-111111111111' ) );
	}

	public function test_filter_true_alone_without_a_valid_signature_is_still_rejected(): void {
		wp_set_current_user( 0 );
		add_filter( 'universal_telegram_support_chat_adapter_rest_authorized', '__return_true' );

		$request = new WP_REST_Request( 'POST', '/' . ContractConstants::UT_REST_NAMESPACE . ContractConstants::UT_REST_PREFIX . '/ensure_channel_case' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'conversation_uuid' => 'a2222222-2222-2222-2222-222222222222',
					'idempotency_key'   => 'ensure-filter-only-1',
				)
			)
		);

		$response = rest_do_request( $request );

		$this->assertTrue( $response->is_error() || $response->get_status() >= 400 );
		$this->assertNull( $this->bindings->find_by_conversation_uuid( 'a2222222-2222-2222-2222-222222222222' ) );
	}

	public function test_valid_signature_and_filter_together_reach_the_acceptor(): void {
		wp_set_current_user( 0 );
		$this->pair_peer( array( 'ensure_channel_case' ) );
		add_filter( 'universal_telegram_support_chat_adapter_rest_authorized', '__return_true' );

		$route   = '/' . ContractConstants::UT_REST_NAMESPACE . ContractConstants::UT_REST_PREFIX . '/ensure_channel_case';
		$body    = array(
			'conversation_uuid' => 'a3333333-3333-3333-3333-333333333333',
			'idempotency_key'   => 'ensure-both-gates-1',
		);
		$request = $this->build_signed_request( 'ensure_channel_case', $route, $body );

		$response = rest_do_request( $request );

		// Discovery is not Compatible in this test environment (no real SC
		// plugin), so the pre-existing require_compatible() gate inside the
		// handler still yields a 503 "unavailable" — but critically the
		// request is no longer denied at the permission layer, proving both
		// authorize_operation() gates passed and control reached the
		// handler unchanged.
		$this->assertFalse( $response->is_error() );
		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'status', $data );
	}

	public function test_operation_not_on_peer_allow_list_is_rejected_even_with_filter_true(): void {
		wp_set_current_user( 0 );
		$this->pair_peer( array( 'notify_operators' ) ); // ensure_channel_case NOT granted.
		add_filter( 'universal_telegram_support_chat_adapter_rest_authorized', '__return_true' );

		$route   = '/' . ContractConstants::UT_REST_NAMESPACE . ContractConstants::UT_REST_PREFIX . '/ensure_channel_case';
		$body    = array(
			'conversation_uuid' => 'a4444444-4444-4444-4444-444444444444',
			'idempotency_key'   => 'ensure-not-allowed-1',
		);
		$request = $this->build_signed_request( 'ensure_channel_case', $route, $body );

		$response = rest_do_request( $request );

		$this->assertTrue( $response->is_error() || $response->get_status() >= 400 );
		$this->assertNull( $this->bindings->find_by_conversation_uuid( 'a4444444-4444-4444-4444-444444444444' ) );
	}

	public function test_body_tamper_after_signing_is_rejected_even_with_filter_true(): void {
		wp_set_current_user( 0 );
		$this->pair_peer( array( 'ensure_channel_case' ) );
		add_filter( 'universal_telegram_support_chat_adapter_rest_authorized', '__return_true' );

		$route   = '/' . ContractConstants::UT_REST_NAMESPACE . ContractConstants::UT_REST_PREFIX . '/ensure_channel_case';
		$request = $this->build_signed_request(
			'ensure_channel_case',
			$route,
			array(
				'conversation_uuid' => 'a5555555-5555-5555-5555-555555555555',
				'idempotency_key'   => 'ensure-tamper-1',
			)
		);
		// Tamper with the body after signing — body hash/signature no
		// longer match.
		$request->set_body(
			wp_json_encode(
				array(
					'conversation_uuid' => 'a5555555-5555-5555-5555-555555555555',
					'idempotency_key'   => 'ensure-tamper-1',
					'extra'             => 'x',
				)
			)
		);

		$response = rest_do_request( $request );

		$this->assertTrue( $response->is_error() || $response->get_status() >= 400 );
		$this->assertNull( $this->bindings->find_by_conversation_uuid( 'a5555555-5555-5555-5555-555555555555' ) );
	}

	public function test_nonce_replay_is_rejected_on_second_delivery(): void {
		wp_set_current_user( 0 );
		$this->pair_peer( array( 'ensure_channel_case' ) );
		add_filter( 'universal_telegram_support_chat_adapter_rest_authorized', '__return_true' );

		$route = '/' . ContractConstants::UT_REST_NAMESPACE . ContractConstants::UT_REST_PREFIX . '/ensure_channel_case';
		$body  = array(
			'conversation_uuid' => 'a6666666-6666-6666-6666-666666666666',
			'idempotency_key'   => 'ensure-replay-1',
		);

		$first  = $this->build_signed_request( 'ensure_channel_case', $route, $body );
		$second = clone $first;

		$first_response  = rest_do_request( $first );
		$second_response = rest_do_request( $second );

		$this->assertFalse( $first_response->is_error() );
		$this->assertTrue( $second_response->is_error() || $second_response->get_status() >= 400 );
	}
}
