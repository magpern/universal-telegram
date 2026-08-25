<?php
/**
 * Unit tests for the ADR-0007 signed Support Chat Contract client's
 * fail-closed gates that do not require a WordPress bootstrap.
 *
 * Full round-trip dispatch (compatible discovery, signed request accepted
 * by a fixture Contract server) is covered by the integration test suite,
 * since DiscoveryClient::resolve() itself requires rest_do_request().
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Tests\SupportChatAdapter;

use PHPUnit\Framework\TestCase;
use UniversalTelegram\SupportChatAdapter\ContractConstants;
use UniversalTelegram\SupportChatAdapter\DiscoveryClient;
use UniversalTelegram\SupportChatAdapter\Auth\PeerRecord;
use UniversalTelegram\SupportChatAdapter\Auth\SignatureSigner;
use UniversalTelegram\SupportChatAdapter\Inbound\SupportChatContractClient;
use UniversalTelegram\Tests\SupportChatAdapter\Auth\Support\FakeOwnKeyManager;
use UniversalTelegram\Tests\SupportChatAdapter\Auth\Support\FakePeerRepository;

/**
 * @covers \UniversalTelegram\SupportChatAdapter\Inbound\SupportChatContractClient
 */
final class SupportChatContractClientTest extends TestCase {

	public function test_unwired_client_fails_closed_for_every_operation(): void {
		$client = new SupportChatContractClient();

		foreach (
			array(
				$client->ingest_operator_reply( 'a', 'k', 'body', 1 ),
				$client->claim( 'a', 1, 'k' ),
				$client->release( 'a', 1, 'k' ),
				$client->resolve( 'a', 1, 'k' ),
				$client->reopen( 'a', 1, 'k' ),
				$client->update_assignment( 'a', 1, 'k' ),
				$client->report_channel_unavailable( 'a', 'adapter_deactivated' ),
				$client->report_delivery_failure( 'a', 'k', 'failed' ),
			) as $result
		) {
			$this->assertFalse( $result['ok'] );
			$this->assertSame( 503, $result['status'] );
			$this->assertSame( SupportChatContractClient::UNAVAILABLE_REASON, $result['reason'] );
		}
	}

	public function test_update_operator_presence_is_not_exposed_as_a_client_method(): void {
		$this->assertFalse( method_exists( SupportChatContractClient::class, 'update_operator_presence' ) );
	}

	public function test_disabled_adapter_fails_closed(): void {
		$client = $this->build_client( adapter_enabled: false, seed_peer: true );

		$result = $client->claim( 'a', 1, 'k' );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( SupportChatContractClient::REASON_NOT_PAIRED, $result['reason'] );
	}

	public function test_unpaired_peer_fails_closed(): void {
		$client = $this->build_client( adapter_enabled: true, seed_peer: false );

		$result = $client->claim( 'a', 1, 'k' );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( SupportChatContractClient::REASON_NOT_PAIRED, $result['reason'] );
	}

	public function test_revoked_peer_fails_closed(): void {
		$client = $this->build_client( adapter_enabled: true, seed_peer: true, peer_status: PeerRecord::STATUS_REVOKED );

		$result = $client->claim( 'a', 1, 'k' );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( SupportChatContractClient::REASON_NOT_PAIRED, $result['reason'] );
	}

	public function test_disabled_peer_fails_closed(): void {
		$client = $this->build_client( adapter_enabled: true, seed_peer: true, peer_status: PeerRecord::STATUS_DISABLED );

		$result = $client->claim( 'a', 1, 'k' );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( SupportChatContractClient::REASON_NOT_PAIRED, $result['reason'] );
	}

	public function test_discovery_incompatible_fails_closed_even_when_paired(): void {
		// No WordPress REST machinery in this bootstrap, so DiscoveryClient
		// itself always resolves Unavailable here — exercising exactly the
		// "discovery does not advertise" fail-closed gate.
		$client = $this->build_client( adapter_enabled: true, seed_peer: true );

		$result = $client->claim( 'a', 1, 'k' );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( SupportChatContractClient::REASON_DISCOVERY_INCOMPATIBLE, $result['reason'] );
	}

	/**
	 * Builds a client wired with fakes for every collaborator except
	 * DiscoveryClient (final, always real — safe here because
	 * rest_do_request() is undefined in this bootstrap).
	 *
	 * @param bool   $adapter_enabled Settings flag.
	 * @param bool   $seed_peer       Whether to seed an SC peer record.
	 * @param string $peer_status     PeerRecord::STATUS_* for the seeded peer.
	 */
	private function build_client( bool $adapter_enabled, bool $seed_peer, string $peer_status = PeerRecord::STATUS_ACTIVE ): SupportChatContractClient {
		$own_key = new FakeOwnKeyManager();
		$peers   = new FakePeerRepository();

		if ( $seed_peer ) {
			$public = $own_key->public_key();
			$peers->seed(
				ContractConstants::PEER_ID,
				new PeerRecord(
					1,
					ContractConstants::PEER_ID,
					'irrelevant-for-this-fixture',
					'irrelevant.0000000000000000',
					ContractConstants::support_chat_to_adapter_operations(),
					null,
					$peer_status,
					gmdate( 'Y-m-d H:i:s' ),
					null,
					null,
					null,
					PeerRecord::STATUS_REVOKED === $peer_status ? gmdate( 'Y-m-d H:i:s' ) : null
				)
			);
			unset( $public );
		}

		return new SupportChatContractClient(
			$peers,
			$own_key,
			new DiscoveryClient(),
			new SignatureSigner( $own_key ),
			$adapter_enabled
		);
	}
}
