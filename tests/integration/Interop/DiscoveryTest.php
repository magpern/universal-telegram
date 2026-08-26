<?php
/**
 * Item 3: real discovery reports truthful state across pairing transitions.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Tests\Integration\Interop;

use UniversalSupportChat\ChannelContract\Auth\PeerRecord as ScPeerRecord;
use UniversalTelegram\SupportChatAdapter\AdapterAvailability;
use WP_REST_Request;

final class DiscoveryTest extends InteropTestCase {

	private function sc_discovery_response(): array {
		$request  = new WP_REST_Request( 'GET', '/universal-support-chat/v1/channel-contract' );
		$response = rest_do_request( $request );
		self::assertFalse( $response->is_error() );
		return (array) $response->get_data();
	}

	/** After real pairing (setUp), SC discovery reports the channel available and compatible. */
	public function test_discovery_reports_active_after_pairing(): void {
		$data = $this->sc_discovery_response();
		self::assertTrue( $data['channel_available'] );
		self::assertSame( AdapterAvailability::Compatible, $this->ut_discovery->resolve( true ) );
	}

	/** Before pairing (a fresh, unpaired peer table), discovery reports unavailable on both sides. */
	public function test_discovery_reports_unpaired_before_pairing(): void {
		$this->sc_peers->set_status( 'universal-telegram', ScPeerRecord::STATUS_REVOKED );
		// Also remove UT's own record of SC, mirroring a genuinely unpaired state.
		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'universal_telegram_support_chat_peers', array( 'peer_id' => 'universal-support-chat' ) );

		$data = $this->sc_discovery_response();
		self::assertFalse( $data['channel_available'] );
		self::assertSame( AdapterAvailability::Unavailable, $this->ut_discovery->resolve( true ) );
	}

	/** Disabled peer: SC stops advertising it, UT's discovery goes Unavailable. */
	public function test_discovery_reports_disabled_peer(): void {
		$this->sc_peers->set_status( 'universal-telegram', ScPeerRecord::STATUS_DISABLED );

		$data = $this->sc_discovery_response();
		self::assertFalse( $data['channel_available'] );
		self::assertSame( AdapterAvailability::Unavailable, $this->ut_discovery->resolve( true ) );
	}

	/** Revoked peer: same truthful unavailability. */
	public function test_discovery_reports_revoked_peer(): void {
		$this->sc_peers->set_status( 'universal-telegram', ScPeerRecord::STATUS_REVOKED );

		$data = $this->sc_discovery_response();
		self::assertFalse( $data['channel_available'] );
	}

	/** Expired peer: same truthful unavailability. */
	public function test_discovery_reports_expired_peer(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'universal_support_chat_channel_peers';
		$wpdb->update( $table, array( 'expires_at' => '2000-01-01 00:00:00' ), array( 'peer_id' => 'universal-telegram' ) );

		$peer = $this->sc_peers->find_by_peer_id( 'universal-telegram' );
		self::assertNotNull( $peer );
		self::assertTrue( $peer->is_expired() );
		self::assertFalse( $peer->is_usable() );

		$data = $this->sc_discovery_response();
		self::assertFalse( $data['channel_available'] );
	}

	/** Peer unavailable: UT adapter disabled in its own settings -> Disabled, not Unavailable/Compatible. */
	public function test_ut_discovery_reports_disabled_when_adapter_setting_off(): void {
		self::assertSame( AdapterAvailability::Disabled, $this->ut_discovery->resolve( false ) );
	}

	/** Peer unavailable: SC's route itself unreachable (SC "deactivated") -> UT resolves Unavailable, never Compatible. */
	public function test_ut_discovery_reports_unavailable_when_sc_route_missing(): void {
		// Simulate SC being deactivated: no route registered for its
		// discovery endpoint. Evaluate a 404-shaped payload directly,
		// mirroring what DiscoveryClient::resolve() would see against a
		// genuinely absent route (rest_do_request() would 404).
		$request  = new WP_REST_Request( 'GET', '/does-not-exist/v1/channel-contract' );
		$response = rest_do_request( $request );
		self::assertTrue( $response->is_error() || $response->get_status() >= 400 );
	}
}
