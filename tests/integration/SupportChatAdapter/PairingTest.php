<?php
/**
 * Integration tests for ADR-0007 §2 key custody and pairing.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Tests\Integration\SupportChatAdapter;

use UniversalTelegram\Audit\AuditLogger;
use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Privacy\Redactor;
use UniversalTelegram\SupportChatAdapter\Auth\KeyId;
use UniversalTelegram\SupportChatAdapter\Auth\OwnKeyManager;
use UniversalTelegram\SupportChatAdapter\Auth\PairingResult;
use UniversalTelegram\SupportChatAdapter\Auth\PairingService;
use UniversalTelegram\SupportChatAdapter\Auth\PeerRecord;
use UniversalTelegram\SupportChatAdapter\Auth\PeerRepository;
use UniversalTelegram\SupportChatAdapter\ContractConstants;
use UniversalTelegram\SupportChatAdapter\Pairing\PairingController;
use WP_UnitTestCase;

/**
 * @covers \UniversalTelegram\SupportChatAdapter\Auth\OwnKeyManager
 * @covers \UniversalTelegram\SupportChatAdapter\Auth\PairingService
 * @covers \UniversalTelegram\SupportChatAdapter\Pairing\PairingController
 */
final class PairingTest extends WP_UnitTestCase {

	private OwnKeyManager $own_key;

	private PeerRepository $peers;

	private PairingService $pairing;

	protected function setUp(): void {
		parent::setUp();

		$schema        = new SchemaHealth();
		$this->own_key = new OwnKeyManager( new CredentialVault() );
		$this->peers   = new PeerRepository( $schema );
		$this->pairing = new PairingService( $this->peers, new AuditLogger( $schema, new Redactor() ) );
	}

	public function test_ensure_key_pair_is_idempotent_and_persists(): void {
		$first  = $this->own_key->ensure_key_pair();
		$second = $this->own_key->ensure_key_pair();

		$this->assertIsArray( $first );
		$this->assertSame( $first, $second );
		$this->assertSame( $first, $this->own_key->public_key() );
	}

	public function test_generated_key_id_matches_the_adr_0007_format_for_this_public_key(): void {
		$own = $this->own_key->ensure_key_pair();
		$this->assertIsArray( $own );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- test assertion, not obfuscation.
		$raw = base64_decode( $own['public_key'], true );
		$this->assertIsString( $raw );
		$this->assertSame( KeyId::compute( ContractConstants::SELF_ID, $raw ), $own['key_id'] );
	}

	public function test_private_key_never_appears_in_the_public_option_or_in_plaintext(): void {
		$this->own_key->ensure_key_pair();

		$public_option = get_option( OwnKeyManager::OPTION_PUBLIC );
		$this->assertIsArray( $public_option );
		$this->assertArrayNotHasKey( 'secret_key', $public_option );
		$this->assertArrayNotHasKey( 'private_key', $public_option );

		$secret_raw = $this->own_key->secret_key_raw();
		$this->assertIsString( $secret_raw );

		$stored_secret_option = get_option( OwnKeyManager::OPTION_SECRET );
		$this->assertIsString( $stored_secret_option );
		// The stored option is CredentialVault's encrypted envelope, never
		// the raw 64-byte secret key or a substring of it.
		$this->assertStringStartsWith( 'ut1:', $stored_secret_option );
		$this->assertStringNotContainsString( $secret_raw, $stored_secret_option );
	}

	public function test_rotate_replaces_the_key_id(): void {
		$first  = $this->own_key->ensure_key_pair();
		$second = $this->own_key->rotate();

		$this->assertIsArray( $first );
		$this->assertIsArray( $second );
		$this->assertNotSame( $first['key_id'], $second['key_id'] );
		$this->assertNotSame( $first['public_key'], $second['public_key'] );
	}

	public function test_pair_is_idempotent_for_an_unchanged_active_key(): void {
		$peer_public = str_repeat( "\x03", 32 );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- test fixture, not obfuscation.
		$peer_public_base64 = base64_encode( $peer_public );
		$key_id             = KeyId::compute( ContractConstants::PEER_ID, $peer_public );

		$first  = $this->pairing->pair(
			ContractConstants::PEER_ID,
			$peer_public_base64,
			$key_id,
			array( 'ensure_channel_case' ),
			null,
			false,
			null
		);
		$second = $this->pairing->pair(
			ContractConstants::PEER_ID,
			$peer_public_base64,
			$key_id,
			array( 'ensure_channel_case' ),
			null,
			false,
			null
		);

		$this->assertTrue( $first->ok() );
		$this->assertSame( PairingResult::REASON_CREATED, $first->reason() );
		$this->assertTrue( $second->ok() );
		$this->assertSame( PairingResult::REASON_UNCHANGED, $second->reason() );
	}

	public function test_pair_replacing_an_active_key_requires_explicit_confirmation(): void {
		$first_public = str_repeat( "\x04", 32 );
		$this->pairing->pair(
			ContractConstants::PEER_ID,
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- test fixture, not obfuscation.
			base64_encode( $first_public ),
			KeyId::compute( ContractConstants::PEER_ID, $first_public ),
			array( 'ensure_channel_case' ),
			null,
			false,
			null
		);

		$replacement_public = str_repeat( "\x05", 32 );
		$without_confirm    = $this->pairing->pair(
			ContractConstants::PEER_ID,
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- test fixture, not obfuscation.
			base64_encode( $replacement_public ),
			KeyId::compute( ContractConstants::PEER_ID, $replacement_public ),
			array( 'ensure_channel_case' ),
			null,
			false,
			null
		);

		$this->assertFalse( $without_confirm->ok() );
		$this->assertSame( PairingResult::REASON_CONFIRMATION_REQUIRED, $without_confirm->reason() );

		$peer = $this->peers->find_by_peer_id( ContractConstants::PEER_ID );
		$this->assertNotNull( $peer );
		$this->assertSame( KeyId::compute( ContractConstants::PEER_ID, $first_public ), $peer->key_id() );

		$with_confirm = $this->pairing->pair(
			ContractConstants::PEER_ID,
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- test fixture, not obfuscation.
			base64_encode( $replacement_public ),
			KeyId::compute( ContractConstants::PEER_ID, $replacement_public ),
			array( 'ensure_channel_case' ),
			null,
			true,
			null
		);

		$this->assertTrue( $with_confirm->ok() );
		$this->assertSame( PairingResult::REASON_REPLACED, $with_confirm->reason() );

		$replaced = $this->peers->find_by_peer_id( ContractConstants::PEER_ID );
		$this->assertNotNull( $replaced );
		$this->assertSame( KeyId::compute( ContractConstants::PEER_ID, $replacement_public ), $replaced->key_id() );
	}

	public function test_revoke_disable_and_enable_transitions(): void {
		$public = str_repeat( "\x06", 32 );
		$this->pairing->pair(
			ContractConstants::PEER_ID,
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- test fixture, not obfuscation.
			base64_encode( $public ),
			KeyId::compute( ContractConstants::PEER_ID, $public ),
			array( 'ensure_channel_case' ),
			null,
			false,
			null
		);

		$this->assertTrue( $this->pairing->disable( ContractConstants::PEER_ID, null ) );
		$peer = $this->peers->find_by_peer_id( ContractConstants::PEER_ID );
		$this->assertNotNull( $peer );
		$this->assertSame( PeerRecord::STATUS_DISABLED, $peer->status() );
		$this->assertFalse( $peer->is_usable() );

		$this->assertTrue( $this->pairing->enable( ContractConstants::PEER_ID, null ) );
		$peer = $this->peers->find_by_peer_id( ContractConstants::PEER_ID );
		$this->assertNotNull( $peer );
		$this->assertTrue( $peer->is_usable() );

		$this->assertTrue( $this->pairing->revoke( ContractConstants::PEER_ID, null ) );
		$peer = $this->peers->find_by_peer_id( ContractConstants::PEER_ID );
		$this->assertNotNull( $peer );
		$this->assertSame( PeerRecord::STATUS_REVOKED, $peer->status() );
		$this->assertFalse( $peer->is_usable() );
		$this->assertNotNull( $peer->revoked_at() );
	}

	public function test_expired_peer_is_not_usable(): void {
		$public = str_repeat( "\x07", 32 );
		$this->peers->create(
			ContractConstants::PEER_ID,
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- test fixture, not obfuscation.
			base64_encode( $public ),
			KeyId::compute( ContractConstants::PEER_ID, $public ),
			array( 'ensure_channel_case' ),
			null,
			gmdate( 'Y-m-d H:i:s', time() - 3600 )
		);

		$peer = $this->peers->find_by_peer_id( ContractConstants::PEER_ID );
		$this->assertNotNull( $peer );
		$this->assertTrue( $peer->is_expired() );
		$this->assertFalse( $peer->is_usable() );
		$this->assertSame( 'expired', $peer->pairing_state() );
	}

	public function test_pair_rejects_a_key_id_that_does_not_match_the_public_key(): void {
		$public = str_repeat( "\x08", 32 );

		$result = $this->pairing->pair(
			ContractConstants::PEER_ID,
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- test fixture, not obfuscation.
			base64_encode( $public ),
			'universal-support-chat.0000000000000000',
			array( 'ensure_channel_case' ),
			null,
			false,
			null
		);

		$this->assertFalse( $result->ok() );
		$this->assertSame( PairingResult::REASON_INVALID_INPUT, $result->reason() );
	}

	public function test_pair_rejects_an_allow_list_containing_an_outbound_only_operation(): void {
		$public = str_repeat( "\x09", 32 );

		$result = $this->pairing->pair(
			ContractConstants::PEER_ID,
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- test fixture, not obfuscation.
			base64_encode( $public ),
			KeyId::compute( ContractConstants::PEER_ID, $public ),
			array( 'claim' ),
			null,
			false,
			null
		);

		$this->assertFalse( $result->ok() );
		$this->assertSame( PairingResult::REASON_INVALID_INPUT, $result->reason() );
	}

	private function controller(): PairingController {
		return new class( $this->own_key, $this->peers, $this->pairing ) extends PairingController {
			public ?string $redirected_to = null;

			protected function redirect_and_exit( string $url ): void {
				$this->redirected_to = $url;
			}
		};
	}

	public function test_pairing_action_requires_both_capabilities_ut_manage_alone_is_denied(): void {
		( new CapabilityRegistrar() )->grant_to_administrator();
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		// Administrator has CapabilityRegistrar::MANAGE via grant_to_administrator()
		// but never universal_support_chat_manage.

		$controller = $this->controller();

		$this->expectException( \WPDieException::class );
		$controller->handle_generate_own_key();
	}

	public function test_pairing_action_requires_both_capabilities_support_chat_manage_alone_is_denied(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user    = get_user_by( 'id', $user_id );
		$this->assertInstanceOf( \WP_User::class, $user );
		$user->add_cap( ContractConstants::SUPPORT_CHAT_MANAGE_CAPABILITY );
		wp_set_current_user( $user_id );

		$controller = $this->controller();

		$this->expectException( \WPDieException::class );
		$controller->handle_generate_own_key();
	}

	public function test_pairing_action_succeeds_with_both_capabilities(): void {
		( new CapabilityRegistrar() )->grant_to_administrator();
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$user     = get_user_by( 'id', $admin_id );
		$this->assertInstanceOf( \WP_User::class, $user );
		$user->add_cap( ContractConstants::SUPPORT_CHAT_MANAGE_CAPABILITY );
		wp_set_current_user( $admin_id );

		$nonce                = wp_create_nonce( PairingController::NONCE_ACTION );
		$_POST['_wpnonce']    = $nonce;
		$_REQUEST['_wpnonce'] = $nonce;

		$controller = $this->controller();
		$controller->handle_generate_own_key();

		$this->assertNotNull( $this->own_key->public_key() );
		unset( $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );
	}
}
