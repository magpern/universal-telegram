<?php
/**
 * Failed REST API request event emission.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Events\Emitters;

use UniversalTelegram\Events\Registry;
use UniversalTelegram\Privacy\Classification;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

/**
 * A thin, reviewed filter callback on rest_request_after_callbacks,
 * excluding any request whose route starts with this plugin's own REST
 * namespace (M02 plan §8.7): that route is a public, unauthenticated-until-
 * verified endpoint by design (docs/adr/0013), so emitting — and
 * potentially notifying about — every rejected request would create a
 * trivial amplification vector and add pure noise. Never modifies the
 * response it is filtering.
 */
final class RestRequestFailureEmitter {

	public const REST_REQUEST_FAILED = 'wordpress.rest_request_failed';

	private const OWN_NAMESPACE_PREFIX = '/universal-telegram/v1/';

	/**
	 * Registers this emitter's event type.
	 *
	 * @param Registry $registry The current request's event registry.
	 */
	public function register_event_types( Registry $registry ): void {
		$fields = array(
			'payload.route'  => Classification::PUBLIC,
			'payload.status' => Classification::PUBLIC,
		);

		$registry->register( self::REST_REQUEST_FAILED, 1, $fields, array_keys( $fields ), array_keys( $fields ) );
	}

	/**
	 * The rest_request_after_callbacks filter callback.
	 *
	 * @param mixed           $response The response, possibly a WP_Error.
	 * @param mixed           $handler  The matched route handler. Not read.
	 * @param WP_REST_Request $request  The request.
	 *
	 * @return mixed The response, unmodified.
	 */
	public function on_rest_request_after_callbacks( $response, $handler, WP_REST_Request $request ) {
		if ( ! is_wp_error( $response ) ) {
			return $response;
		}

		$route = $request->get_route();

		// The one, unconditional, non-configurable feedback-loop exclusion
		// for this emitter: never emit for the plugin's own REST namespace,
		// which legitimately receives malformed or malicious traffic by
		// design.
		if ( 0 === strpos( $route, self::OWN_NAMESPACE_PREFIX ) ) {
			return $response;
		}

		$status = $response instanceof WP_Error ? (int) ( $response->get_error_data()['status'] ?? WP_REST_Server::ERROR ) : 500;

		universal_telegram_emit_event(
			self::REST_REQUEST_FAILED,
			array(
				'payload' => array(
					'route'  => $route,
					'status' => $status,
				),
			),
			wp_generate_uuid4()
		);

		return $response;
	}
}
