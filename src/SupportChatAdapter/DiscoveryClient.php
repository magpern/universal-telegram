<?php
/**
 * Support Chat Contract v1 discovery client.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\SupportChatAdapter;

use WP_REST_Request;

/**
 * Discovers whether Support Chat advertises a compatible, available Contract
 * v1 with the Adapter M1 operation set. Fail-closed: version match alone is
 * not enough — channel_available must be true and every required operation
 * must be advertised. Current SC-M02 inert discovery (channel_available false)
 * therefore yields Unavailable.
 */
final class DiscoveryClient {

	/**
	 * Resolves adapter availability for the current site.
	 *
	 * @param bool $adapter_enabled Operator settings flag.
	 */
	public function resolve( bool $adapter_enabled ): AdapterAvailability {
		if ( ! $adapter_enabled ) {
			return AdapterAvailability::Disabled;
		}

		if ( ! function_exists( 'rest_do_request' ) ) {
			return AdapterAvailability::Unavailable;
		}

		$request  = new WP_REST_Request( 'GET', ContractConstants::SC_DISCOVERY_ROUTE );
		$response = rest_do_request( $request );

		if ( $response->is_error() ) {
			return AdapterAvailability::Unavailable;
		}

		$status = $response->get_status();
		if ( $status < 200 || $status >= 300 ) {
			return AdapterAvailability::Unavailable;
		}

		$data = $response->get_data();
		if ( ! is_array( $data ) ) {
			return AdapterAvailability::Unavailable;
		}

		return $this->evaluate( $data );
	}

	/**
	 * Evaluates a discovery payload without transport.
	 *
	 * Compatible only when all of the following hold:
	 * - contract_version is exactly support-channel-contract/v1
	 * - channel_available is true
	 * - operations advertises every Adapter M1 required capability
	 *
	 * @param array<string, mixed> $data Discovery response body.
	 */
	public function evaluate( array $data ): AdapterAvailability {
		$version = isset( $data['contract_version'] ) && is_string( $data['contract_version'] )
			? $data['contract_version']
			: '';

		if ( ContractConstants::CONTRACT_VERSION_ID !== $version ) {
			return AdapterAvailability::Unavailable;
		}

		$channel_available = isset( $data['channel_available'] ) && true === $data['channel_available'];
		if ( ! $channel_available ) {
			return AdapterAvailability::Unavailable;
		}

		$operations = isset( $data['operations'] ) && is_array( $data['operations'] )
			? $data['operations']
			: array();

		$advertised = array();
		foreach ( $operations as $operation ) {
			if ( is_string( $operation ) && '' !== $operation ) {
				$advertised[] = $operation;
			}
		}

		foreach ( ContractConstants::required_operations() as $required ) {
			if ( ! in_array( $required, $advertised, true ) ) {
				return AdapterAvailability::Unavailable;
			}
		}

		return AdapterAvailability::Compatible;
	}
}
