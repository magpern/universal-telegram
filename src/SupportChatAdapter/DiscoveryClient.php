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
 * Discovers whether Support Chat advertises a compatible Contract v1.
 * Fail-closed: any transport/schema/version mismatch yields Unavailable.
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

		$version = isset( $data['contract_version'] ) && is_string( $data['contract_version'] )
			? $data['contract_version']
			: '';

		if ( ContractConstants::CONTRACT_VERSION_ID !== $version ) {
			return AdapterAvailability::Unavailable;
		}

		return AdapterAvailability::Compatible;
	}
}
