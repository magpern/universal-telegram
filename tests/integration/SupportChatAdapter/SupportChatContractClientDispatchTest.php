<?php
/**
 * Integration tests for signed outbound Contract v1 dispatch, against a
 * local fixture standing in for SC-M03's authenticated Contract server
 * (per UT Adapter M1 plan v2 §7 — no live Support Chat server is required
 * to exercise this plugin's own signing/dispatch behaviour).
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Tests\Integration\SupportChatAdapter;

use UniversalTelegram\Core\Configuration\Settings;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\SupportChatAdapter\Auth\OwnKeyManager;
use UniversalTelegram\SupportChatAdapter\Auth\PeerRepository;
use UniversalTelegram\SupportChatAdapter\Auth\SignatureSigner;
use UniversalTelegram\SupportChatAdapter\ContractConstants;
use UniversalTelegram\SupportChatAdapter\DiscoveryClient;
use UniversalTelegram\SupportChatAdapter\Inbound\SupportChatContractClient;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * @covers \UniversalTelegram\SupportChatAdapter\Inbound\SupportChatContractClient
 */
final class SupportChatContractClientDispatchTest extends WP_UnitTestCase {

	private const FIXTURE_NAMESPACE = 'universal-support-chat/v1';

	/**
	 * Headers captured from the last fixture Contract call, keyed by the
	 * lowercase header name WP_REST_Request normalizes to.
	 *
	 * @var array<string, string>|null
	 */
	private static ?array $captured_headers = null;

	/**
	 * Raw body captured from the last fixture Contract call.
	 *
	 * @var string|null
	 */
	private static ?string $captured_body = null;

	/**
	 * Configurable canned response for the fixture Contract call.
	 *
	 * @var array{status: int, body: array<string, mixed>}
	 */
	private static array $fixture_response = array(
		'status' => 200,
		'body'   => array( 'ok' => true ),
	);

	private SchemaHealth $schema;

	private OwnKeyManager $own_key;

	private PeerRepository $peers;

	private SupportChatContractClient $client;

	protected function setUp(): void {
		parent::setUp();

		self::$captured_headers = null;
		self::$captured_body    = null;
		self::$fixture_response = array(
			'status' => 200,
			'body'   => array( 'ok' => true ),
		);

		add_action( 'rest_api_init', array( self::class, 'register_fixture_routes' ) );
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core REST bootstrap hook used to register routes in tests.
		do_action( 'rest_api_init' );

		$this->schema  = new SchemaHealth();
		$this->own_key = new OwnKeyManager( new CredentialVault() );
		$this->own_key->ensure_key_pair();
		$this->peers = new PeerRepository( $this->schema );

		$this->client = new SupportChatContractClient(
			$this->peers,
			$this->own_key,
			new DiscoveryClient(),
			new SignatureSigner( $this->own_key ),
			true
		);

		$settings = new Settings();
		update_option(
			Settings::OPTION_NAME,
			array_merge( $settings->get(), array( 'support_chat_adapter_enabled' => true ) )
		);
	}

	/**
	 * Registers the fixture discovery + Contract routes.
	 */
	public static function register_fixture_routes(): void {
		register_rest_route(
			self::FIXTURE_NAMESPACE,
			'/channel-contract',
			array(
				'methods'             => 'GET',
				'callback'            => static fn () => new WP_REST_Response(
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

		register_rest_route(
			self::FIXTURE_NAMESPACE,
			'/contract/(?P<operation>[a-z_]+)',
			array(
				'methods'             => 'POST',
				'callback'            => static function ( WP_REST_Request $request ): WP_REST_Response {
					self::$captured_headers = array(
						'contract_version' => (string) $request->get_header( 'X-SC-Contract-Version' ),
						'auth_profile'     => (string) $request->get_header( 'X-SC-Auth-Profile' ),
						'sender'           => (string) $request->get_header( 'X-SC-Sender' ),
						'audience'         => (string) $request->get_header( 'X-SC-Audience' ),
						'key_id'           => (string) $request->get_header( 'X-SC-Key-Id' ),
						'timestamp'        => (string) $request->get_header( 'X-SC-Timestamp' ),
						'nonce'            => (string) $request->get_header( 'X-SC-Nonce' ),
						'body_sha256'      => (string) $request->get_header( 'X-SC-Body-Sha256' ),
						'signature'        => (string) $request->get_header( 'X-SC-Signature' ),
					);
					self::$captured_body = (string) $request->get_body();

					return new WP_REST_Response( self::$fixture_response['body'], self::$fixture_response['status'] );
				},
				'permission_callback' => '__return_true',
			)
		);
	}

	public function test_paired_client_signs_and_dispatches_a_verifiable_request(): void {
		$this->peers->create(
			ContractConstants::PEER_ID,
			'irrelevant-fixture-does-not-verify-it',
			'irrelevant.0000000000000000',
			ContractConstants::support_chat_to_adapter_operations(),
			null
		);

		$result = $this->client->claim( 'conversation-uuid-1', 42, 'idem-1' );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 200, $result['status'] );

		$this->assertNotNull( self::$captured_headers );
		$this->assertSame( ContractConstants::CONTRACT_VERSION_ID, self::$captured_headers['contract_version'] );
		$this->assertSame( ContractConstants::AUTH_PROFILE_ID, self::$captured_headers['auth_profile'] );
		$this->assertSame( ContractConstants::SELF_ID, self::$captured_headers['sender'] );
		$this->assertSame( ContractConstants::PEER_ID, self::$captured_headers['audience'] );

		$own = $this->own_key->public_key();
		$this->assertIsArray( $own );
		$this->assertSame( $own['key_id'], self::$captured_headers['key_id'] );

		// The captured body hash must match the exact raw body bytes sent —
		// proof the signer hashed what was actually transmitted.
		$this->assertSame( hash( 'sha256', (string) self::$captured_body ), self::$captured_headers['body_sha256'] );

		$decoded = json_decode( (string) self::$captured_body, true );
		$this->assertIsArray( $decoded );
		$this->assertSame( 'conversation-uuid-1', $decoded['channel_case_ref'] );
		$this->assertSame( 42, $decoded['operator_user_id'] );
		$this->assertSame( 'idem-1', $decoded['idempotency_key'] );

		// And the signature genuinely verifies against this plugin's own
		// public key over the exact ADR-0007 canonical string.
		$route     = '/' . SupportChatContractClient::SC_NAMESPACE . '/contract/claim';
		$canonical = implode(
			"\n",
			array(
				ContractConstants::AUTH_PROFILE_ID,
				ContractConstants::CONTRACT_VERSION_ID,
				self::$captured_headers['sender'],
				self::$captured_headers['audience'],
				self::$captured_headers['key_id'],
				self::$captured_headers['timestamp'],
				self::$captured_headers['nonce'],
				'POST',
				$route,
				self::$captured_headers['body_sha256'],
			)
		);
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- test assertion, not obfuscation.
		$signature = base64_decode( self::$captured_headers['signature'], true );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- test assertion, not obfuscation.
		$public_raw = base64_decode( $own['public_key'], true );
		$this->assertIsString( $signature );
		$this->assertIsString( $public_raw );
		$this->assertTrue( sodium_crypto_sign_verify_detached( $signature, $canonical, $public_raw ) );
	}

	public function test_fixture_failure_response_is_surfaced_as_not_ok(): void {
		$this->peers->create(
			ContractConstants::PEER_ID,
			'irrelevant',
			'irrelevant.0000000000000000',
			ContractConstants::support_chat_to_adapter_operations(),
			null
		);
		self::$fixture_response = array(
			'status' => 409,
			'body'   => array(
				'ok'     => false,
				'reason' => 'already_claimed',
			),
		);

		$result = $this->client->claim( 'binding-uuid-2', 1, 'idem-2' );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 409, $result['status'] );
		$this->assertSame( 'already_claimed', $result['reason'] );
	}

	public function test_unpaired_client_never_reaches_the_fixture(): void {
		// No peer created — client must fail closed before dispatch.
		$result = $this->client->claim( 'binding-uuid-3', 1, 'idem-3' );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( SupportChatContractClient::REASON_NOT_PAIRED, $result['reason'] );
		$this->assertNull( self::$captured_headers );
	}

	public function test_report_channel_unavailable_dispatches_with_reason_code(): void {
		$this->peers->create(
			ContractConstants::PEER_ID,
			'irrelevant',
			'irrelevant.0000000000000000',
			ContractConstants::support_chat_to_adapter_operations(),
			null
		);

		$result = $this->client->report_channel_unavailable( 'binding-uuid-4', 'adapter_deactivated' );

		$this->assertTrue( $result['ok'] );
		$decoded = json_decode( (string) self::$captured_body, true );
		$this->assertIsArray( $decoded );
		$this->assertSame( 'adapter_deactivated', $decoded['reason_code'] );
	}
}
