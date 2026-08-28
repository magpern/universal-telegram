<?php
/**
 * ADR-0044 interop: real two-way pairing and truthful discovery between the
 * transport-only Universal Telegram adapter and the current Universal
 * Support Chat.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Tests\Integration\Interop;

use UniversalTelegram\SupportChatAdapter\AdapterAvailability;
use WP_REST_Request;

/**
 * @coversNothing
 */
final class PairingAndDiscoveryTest extends InteropTestCase {

	public function test_setup_performed_a_real_mutual_pairing(): void {
		$sc_view_of_ut = $this->sc_peers->find_by_peer_id( 'universal-telegram' );
		$ut_view_of_sc = $this->ut_peers->find_by_peer_id( 'universal-support-chat' );

		self::assertNotNull( $sc_view_of_ut );
		self::assertNotNull( $ut_view_of_sc );
		self::assertTrue( $sc_view_of_ut->is_usable() );
		self::assertTrue( $ut_view_of_sc->is_usable() );
		self::assertNotSame(
			$sc_view_of_ut->public_key_base64(),
			$ut_view_of_sc->public_key_base64(),
			'each side must store the peer public key, never its own'
		);
	}

	public function test_discovery_reports_channel_available_after_pairing(): void {
		$request  = new WP_REST_Request( 'GET', '/universal-support-chat/v1/channel-contract' );
		$response = rest_do_request( $request );
		self::assertFalse( $response->is_error() );

		$data = (array) $response->get_data();
		self::assertTrue( $data['channel_available'] );

		self::assertSame( AdapterAvailability::Compatible, $this->ut_discovery->resolve( true ) );
	}

	public function test_ut_discovery_is_disabled_when_the_adapter_setting_is_off(): void {
		self::assertSame( AdapterAvailability::Disabled, $this->ut_discovery->resolve( false ) );
	}
}
