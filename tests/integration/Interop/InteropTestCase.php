<?php
/**
 * Shared setup for the SC<->UT cross-plugin interoperability suite.
 *
 * Wires REAL collaborators on both sides (no mocking of the Contract
 * client/server/signer/verifier/pairing/replay store) and performs a REAL
 * pairing handshake: each plugin generates its own Ed25519 key pair via its
 * own OwnKeyManager (private key vault-encrypted, never exposed), and the
 * two public keys/key IDs are exchanged and paired via each plugin's own
 * PairingService — exactly the real public-key exchange an administrator
 * holding both management capabilities would perform through the Hub UI,
 * just invoked in-process instead of through admin-post.
 *
 * REST routes on both sides are the REAL ones registered by each plugin's
 * own production bootstrap (Plugin::boot(), loaded for real by
 * bootstrap.php) — this suite never registers a second, competing
 * controller instance for an inbound route. WordPress resolves duplicate
 * route registrations to the first-registered handler, so a second
 * test-only registration would silently never run; relying on the real
 * bootstrap is both correct (it is what "both plugins installed together"
 * means) and the only way to reliably exercise the actual production wiring.
 *
 * The only test double in this suite is a `pre_http_request` filter
 * standing in for the external Telegram Bot API network boundary (never
 * part of the Contract v1 chain under test), scoped to api.telegram.org
 * requests only, so `ensure_channel_case` can exercise a real
 * forum-topic-binding creation through the real, production-wired
 * EnsureChannelCaseService without a live bot token or network access.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Tests\Integration\Interop;

use UniversalSupportChat\Audit\AuditLogger as ScAuditLogger;
use UniversalSupportChat\ChannelContract\Auth\ContractOperations as ScContractOperations;
use UniversalSupportChat\ChannelContract\Auth\OwnKeyManager as ScOwnKeyManager;
use UniversalSupportChat\ChannelContract\Auth\PairingService as ScPairingService;
use UniversalSupportChat\ChannelContract\Auth\PeerRepository as ScPeerRepository;
use UniversalSupportChat\ChannelContract\ChannelStatusRepository as ScChannelStatusRepository;
use UniversalSupportChat\ChannelContract\ContractDiscovery;
use UniversalSupportChat\ChannelContract\Outbound\AdapterContractClient;
use UniversalSupportChat\ChannelContract\Outbound\InProcessContractTransport;
use UniversalSupportChat\ChannelContract\Outbound\SignatureSigner as ScSignatureSigner;
use UniversalSupportChat\Conversations\ConversationRepository;
use UniversalSupportChat\Conversations\MessageRepository as ScMessageRepository;
use UniversalSupportChat\Core\Capabilities\CapabilityRegistrar as ScCapabilityRegistrar;
use UniversalSupportChat\Core\Security\CredentialVault as ScCredentialVault;
use UniversalSupportChat\Persistence\SchemaHealth as ScSchemaHealth;
use UniversalSupportChat\Privacy\Redactor as ScRedactor;
use UniversalTelegram\Audit\AuditLogger as UtAuditLogger;
use UniversalTelegram\Core\Capabilities\CapabilityRegistrar as UtCapabilityRegistrar;
use UniversalTelegram\Core\Configuration\Settings;
use UniversalTelegram\Core\Security\CredentialVault as UtCredentialVault;
use UniversalTelegram\Persistence\SchemaHealth as UtSchemaHealth;
use UniversalTelegram\Privacy\Redactor as UtRedactor;
use UniversalTelegram\SupportChatAdapter\Auth\KeyId as UtKeyId;
use UniversalTelegram\SupportChatAdapter\Auth\OwnKeyManager as UtOwnKeyManager;
use UniversalTelegram\SupportChatAdapter\Auth\PairingService as UtPairingService;
use UniversalTelegram\SupportChatAdapter\Auth\PeerRepository as UtPeerRepository;
use UniversalTelegram\SupportChatAdapter\Auth\SignatureSigner as UtSignatureSigner;
use UniversalTelegram\SupportChatAdapter\ChannelBindingRepository;
use UniversalTelegram\SupportChatAdapter\ContractConstants;
use UniversalTelegram\SupportChatAdapter\DeliveryIdempotencyRepository;
use UniversalTelegram\SupportChatAdapter\DiscoveryClient;
use UniversalTelegram\SupportChatAdapter\Inbound\SupportChatContractClient;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;
use UniversalTelegram\Telegram\Configuration\Destination;
use UniversalTelegram\Telegram\Configuration\DestinationKind;
use UniversalTelegram\Telegram\Configuration\DestinationRepository;
use WP_UnitTestCase;

/**
 * Base class for every interop test. Boots real collaborators on both
 * sides and performs a real, two-way key pairing.
 */
abstract class InteropTestCase extends WP_UnitTestCase {

	protected ConversationRepository $sc_conversations;
	protected ScMessageRepository $sc_messages;
	protected ScChannelStatusRepository $sc_channel_status;
	protected ScPeerRepository $sc_peers;
	protected ContractDiscovery $sc_discovery;
	protected AdapterContractClient $sc_outbound_client;

	protected ChannelBindingRepository $ut_bindings;
	protected DeliveryIdempotencyRepository $ut_delivery_keys;
	protected BotProfileRepository $ut_bots;
	protected DestinationRepository $ut_destinations;
	protected UtPeerRepository $ut_peers;
	protected DiscoveryClient $ut_discovery;
	protected SupportChatContractClient $ut_outbound_client;
	protected Settings $ut_settings;

	protected int $bot_id;
	protected Destination $parent_destination;

	protected function setUp(): void {
		parent::setUp();

		// --- Support Chat collaborators (real, no mocking). -----------
		$sc_schema               = new ScSchemaHealth();
		$this->sc_conversations  = new ConversationRepository( $sc_schema );
		$this->sc_messages       = new ScMessageRepository( $sc_schema, new ScCredentialVault() );
		$this->sc_channel_status = new ScChannelStatusRepository( $sc_schema );
		$this->sc_peers          = new ScPeerRepository( $sc_schema );
		$sc_audit                = new ScAuditLogger( $sc_schema, new ScRedactor() );
		$sc_pairing              = new ScPairingService( $this->sc_peers, $sc_audit );
		$sc_own_key              = new ScOwnKeyManager( new ScCredentialVault() );
		// Read-only local handle for assertions; SC's real REST discovery
		// route is the production-registered one (Plugin::boot()), not a
		// second registration here — see class docblock.
		$this->sc_discovery = new ContractDiscovery( $this->sc_peers );

		$this->sc_outbound_client = new AdapterContractClient(
			$this->sc_peers,
			new ScSignatureSigner( $sc_own_key ),
			new InProcessContractTransport(),
			$sc_audit
		);

		// --- Universal Telegram collaborators (real, no mocking). -----
		$ut_schema              = new UtSchemaHealth();
		$this->ut_bindings      = new ChannelBindingRepository( $ut_schema );
		$this->ut_delivery_keys = new DeliveryIdempotencyRepository( $ut_schema );
		$this->ut_bots          = new BotProfileRepository( $ut_schema, new UtCredentialVault() );
		$this->ut_destinations  = new DestinationRepository( $ut_schema );
		$ut_audit               = new UtAuditLogger( $ut_schema, new UtRedactor() );
		$this->ut_peers         = new UtPeerRepository( $ut_schema );
		$ut_pairing             = new UtPairingService( $this->ut_peers, $ut_audit );
		$ut_own_key             = new UtOwnKeyManager( new UtCredentialVault() );
		$this->ut_discovery     = new DiscoveryClient();
		$this->ut_settings      = new Settings();

		// UT's real REST route (support-chat/ensure_channel_case etc.) is
		// the production-registered one (Plugin::boot(), constructed with
		// the real TelegramApiClient) — not a second registration here; see
		// class docblock. The Telegram Bot API network boundary itself is
		// faked below via pre_http_request, scoped to api.telegram.org only.
		remove_all_filters( 'universal_telegram_support_chat_adapter_rest_authorized' );
		$this->install_fake_telegram_http();

		// UT->SC contract client requires the adapter to be enabled and
		// discovery to resolve Compatible; those are asserted for real
		// below (real pairing + real SC discovery route), never faked.
		update_option(
			Settings::OPTION_NAME,
			array_merge( $this->ut_settings->get(), array( 'support_chat_adapter_enabled' => true ) )
		);
		$this->ut_settings = new Settings();

		$this->ut_outbound_client = new SupportChatContractClient(
			$this->ut_peers,
			$ut_own_key,
			$this->ut_discovery,
			new UtSignatureSigner( $ut_own_key ),
			true
		);

		do_action( 'rest_api_init' ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		// --- Real capability grants (item 2). --------------------------
		( new ScCapabilityRegistrar() )->grant_to_administrator();
		( new UtCapabilityRegistrar() )->grant_to_administrator();

		// --- Real two-way pairing: real key generation + real exchange. ---
		$sc_key = $sc_own_key->ensure_key_pair();
		$ut_key = $ut_own_key->ensure_key_pair();
		self::assertIsArray( $sc_key );
		self::assertIsArray( $ut_key );

		self::assertSame( UtKeyId::compute( ContractConstants::PEER_ID, base64_decode( $sc_key['public_key'], true ) ), $sc_key['key_id'] );

		$sc_pair_result = $sc_pairing->pair(
			'universal-telegram',
			$ut_key['public_key'],
			$ut_key['key_id'],
			ScContractOperations::ADAPTER_TO_SUPPORT_CHAT,
			UtCapabilityRegistrar::MANAGE,
			false,
			1,
			null,
			'universal-telegram/v1/support-chat'
		);
		self::assertTrue( $sc_pair_result->ok(), 'SC failed to pair UT as a peer: ' . (string) $sc_pair_result->reason() );

		$ut_pair_result = $ut_pairing->pair(
			'universal-support-chat',
			$sc_key['public_key'],
			$sc_key['key_id'],
			ContractConstants::support_chat_to_adapter_operations(),
			ScCapabilityRegistrar::MANAGE,
			false,
			1
		);
		self::assertTrue( $ut_pair_result->ok(), 'UT failed to pair SC as a peer: ' . (string) $ut_pair_result->reason() );

		// --- Safe test bot/destination fixtures (item 5's non-secret data). ---
		$bot = $this->ut_bots->create( 'interop-test-bot', 'test-token-not-a-real-secret' );
		self::assertNotNull( $bot );
		$this->bot_id = $bot->id();

		$parent = $this->ut_destinations->create( $this->bot_id, DestinationKind::SUPERGROUP, '-1000000000001', null, 'interop-parent' );
		self::assertNotNull( $parent );
		$this->parent_destination = $parent;

		update_option(
			Settings::OPTION_NAME,
			array_merge(
				$this->ut_settings->get(),
				array(
					'support_chat_adapter_enabled'        => true,
					'support_chat_adapter_bot_id'         => $this->bot_id,
					'support_chat_adapter_destination_id' => $parent->id(),
				)
			)
		);
		$this->ut_settings = new Settings();
	}

	protected function tearDown(): void {
		remove_filter( 'pre_http_request', array( $this, 'fake_telegram_http_response' ), 10 );
		parent::tearDown();
	}

	/**
	 * Item-scoped test double: intercepts only requests to
	 * api.telegram.org (the external Telegram Bot API network boundary,
	 * never part of the Contract v1 chain under test) so the real,
	 * production-wired EnsureChannelCaseService can create a real
	 * forum-topic binding without a live bot token or network access.
	 * Every other collaborator in this suite — signing, verification,
	 * pairing, discovery, repositories, REST dispatch — is real.
	 */
	private function install_fake_telegram_http(): void {
		add_filter( 'pre_http_request', array( $this, 'fake_telegram_http_response' ), 10, 3 );
	}

	/**
	 * @param false|array<string, mixed> $preempt Whether to preempt the request.
	 * @param array<string, mixed>       $args    HTTP request args.
	 * @param string                     $url     Request URL.
	 *
	 * @return false|array<string, mixed>
	 */
	public function fake_telegram_http_response( $preempt, array $args, string $url ) {
		if ( false === strpos( $url, 'api.telegram.org' ) ) {
			return $preempt;
		}

		if ( false !== strpos( $url, '/createForumTopic' ) ) {
			static $next_thread_id = 100;
			++$next_thread_id;

			$body = $args['body'] ?? array();
			if ( is_string( $body ) ) {
				parse_str( $body, $body );
			}
			$name = is_array( $body ) && isset( $body['name'] ) && is_string( $body['name'] ) ? $body['name'] : '';

			return array(
				'response' => array( 'code' => 200 ),
				'body'     => (string) wp_json_encode(
					array(
						'ok'     => true,
						'result' => array(
							'message_thread_id' => $next_thread_id,
							'name'              => $name,
						),
					)
				),
			);
		}

		return array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) wp_json_encode(
				array(
					'ok'     => true,
					'result' => array(),
				)
			),
		);
	}

	/**
	 * Creates a real SC conversation with a bound owner user, returning its
	 * UUID (the interim channel_case_ref convention for UT->SC ops).
	 */
	protected function create_sc_conversation(): string {
		$owner_id     = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$conversation = $this->sc_conversations->create( $owner_id );
		self::assertNotNull( $conversation );

		// Real conversations transition new -> open once visitor activity
		// starts (e.g. the first stored message); UT's resolve/reopen/claim
		// contract ops assume an already-open conversation, so mirror that
		// real precondition via SC's own transition method rather than
		// invoking resolve/reopen against an untouched "new" conversation.
		$opened = $this->sc_conversations->transition( $conversation, \UniversalSupportChat\Conversations\ConversationStatus::OPEN );
		self::assertNotNull( $opened );

		return $conversation->uuid();
	}

	/**
	 * Ensures a real UT channel binding (channel_case_ref) via the real
	 * SC->UT outbound client, returning its opaque ref.
	 */
	protected function ensure_ut_channel_case( string $conversation_uuid ): string {
		$result = $this->sc_outbound_client->ensure_channel_case( 'universal-telegram', $conversation_uuid, 'escalated' );
		self::assertTrue( $result['ok'], 'ensure_channel_case failed: ' . (string) $result['reason'] );
		self::assertNotSame( '', $result['channel_case_ref'] );

		return $result['channel_case_ref'];
	}
}
