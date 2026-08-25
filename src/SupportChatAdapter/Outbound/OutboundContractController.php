<?php
/**
 * REST acceptors for Support Chat → Universal Telegram Contract ops.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\SupportChatAdapter\Outbound;

use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;
use UniversalTelegram\Core\Configuration\Settings;
use UniversalTelegram\SupportChatAdapter\AdapterAvailability;
use UniversalTelegram\SupportChatAdapter\Auth\SignatureVerifier;
use UniversalTelegram\SupportChatAdapter\ContractConstants;
use UniversalTelegram\SupportChatAdapter\DiscoveryClient;
use UniversalTelegram\Telegram\Configuration\DestinationRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Exposes ensure / notify / backfill / deliver REST routes for Support Chat.
 *
 * Mutating acceptors stay closed unless **both** independent gates pass
 * (ADR-0038 §4, defense in depth — neither alone is sufficient):
 *
 * 1. An ADR-0007 §3 Ed25519-signed Contract v1 request from Support Chat's
 *    currently paired, active peer key, verified by SignatureVerifier
 *    (signature, sender/audience, allow-list, timestamp window, nonce
 *    replay, body hash — all checked before any acceptor runs).
 * 2. Compatible discovery **and** an explicit authenticated Contract
 *    assertion via the `universal_telegram_support_chat_adapter_rest_authorized`
 *    filter (default false), retained exactly as before.
 *
 * Holding only `universal_support_chat_manage` or even UT MANAGE — signed
 * or not — must not turn these routes into a general Telegram-send endpoint.
 */
final class OutboundContractController {

	/**
	 * Constructor.
	 *
	 * @param DiscoveryClient          $discovery     Discovery client.
	 * @param Settings                 $settings      Plugin settings.
	 * @param DestinationRepository    $destinations  Destinations.
	 * @param EnsureChannelCaseService $ensure        Ensure service.
	 * @param NotifyOperatorsService   $notify        Notify service.
	 * @param BackfillService          $backfill      Backfill service.
	 * @param DeliverMessageService    $deliver       Deliver service.
	 * @param SignatureVerifier        $verifier      ADR-0007 signature verifier.
	 */
	public function __construct(
		private readonly DiscoveryClient $discovery,
		private readonly Settings $settings,
		private readonly DestinationRepository $destinations,
		private readonly EnsureChannelCaseService $ensure,
		private readonly NotifyOperatorsService $notify,
		private readonly BackfillService $backfill,
		private readonly DeliverMessageService $deliver,
		private readonly SignatureVerifier $verifier
	) {}

	/**
	 * Registers REST routes.
	 */
	public function register_routes(): void {
		$ns     = ContractConstants::UT_REST_NAMESPACE;
		$prefix = ContractConstants::UT_REST_PREFIX;

		register_rest_route(
			$ns,
			$prefix . '/ensure_channel_case',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_ensure' ),
				'permission_callback' => fn ( WP_REST_Request $request ): bool => $this->authorize_operation( $request, 'ensure_channel_case' ),
			)
		);
		register_rest_route(
			$ns,
			$prefix . '/notify_operators',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_notify' ),
				'permission_callback' => fn ( WP_REST_Request $request ): bool => $this->authorize_operation( $request, 'notify_operators' ),
			)
		);
		register_rest_route(
			$ns,
			$prefix . '/deliver_transcript_backfill',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_backfill' ),
				'permission_callback' => fn ( WP_REST_Request $request ): bool => $this->authorize_operation( $request, 'deliver_transcript_backfill' ),
			)
		);
		register_rest_route(
			$ns,
			$prefix . '/deliver_message',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_deliver' ),
				'permission_callback' => fn ( WP_REST_Request $request ): bool => $this->authorize_operation( $request, 'deliver_message' ),
			)
		);
		register_rest_route(
			$ns,
			$prefix . '/adapter-status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_status' ),
				'permission_callback' => array( $this, 'authorize_status' ),
			)
		);
	}

	/**
	 * Authorises diagnostic adapter-status reads (UT manage only).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function authorize_status( WP_REST_Request $request ): bool {
		unset( $request );
		return current_user_can( CapabilityRegistrar::MANAGE );
	}

	/**
	 * Authorises one SC → UT mutating Contract call. Requires, in order,
	 * BOTH a valid ADR-0007 signature for this exact operation AND the
	 * pre-existing discovery/filter gate — neither is ever sufficient alone
	 * (ADR-0038 §4).
	 *
	 * @param WP_REST_Request $request   Request.
	 * @param string          $operation Contract v1 operation this route serves.
	 */
	private function authorize_operation( WP_REST_Request $request, string $operation ): bool {
		if ( ! $this->verify_signed_request( $request, $operation ) ) {
			return false;
		}

		return $this->authorize_mutation( $request );
	}

	/**
	 * Verifies the ADR-0007 §3 signature on one inbound Support Chat →
	 * adapter request. A pure gate: never mutates anything, never leaks
	 * which specific check failed.
	 *
	 * @param WP_REST_Request $request   Request.
	 * @param string          $operation Contract v1 operation this route serves.
	 */
	private function verify_signed_request( WP_REST_Request $request, string $operation ): bool {
		$raw_body = (string) $request->get_body();
		$headers  = array(
			'contract_version' => (string) ( $request->get_header( 'X-SC-Contract-Version' ) ?? '' ),
			'auth_profile'     => (string) ( $request->get_header( 'X-SC-Auth-Profile' ) ?? '' ),
			'sender'           => (string) ( $request->get_header( 'X-SC-Sender' ) ?? '' ),
			'audience'         => (string) ( $request->get_header( 'X-SC-Audience' ) ?? '' ),
			'key_id'           => (string) ( $request->get_header( 'X-SC-Key-Id' ) ?? '' ),
			'timestamp'        => (string) ( $request->get_header( 'X-SC-Timestamp' ) ?? '' ),
			'nonce'            => (string) ( $request->get_header( 'X-SC-Nonce' ) ?? '' ),
			'body_sha256'      => (string) ( $request->get_header( 'X-SC-Body-Sha256' ) ?? '' ),
			'signature'        => (string) ( $request->get_header( 'X-SC-Signature' ) ?? '' ),
		);

		$route = $request->get_route();
		if ( '' === $route ) {
			$route = '/' . ContractConstants::UT_REST_NAMESPACE . ContractConstants::UT_REST_PREFIX . '/' . $operation;
		}

		$has_query_params = array() !== $request->get_query_params();

		$result = $this->verifier->verify( 'POST', $route, $raw_body, $headers, $operation, $has_query_params );

		return $result->ok();
	}

	/**
	 * Existing pre-ADR-0007 discovery/filter gate. Requires Compatible
	 * discovery and an explicit authenticated Contract assertion via
	 * filter. Does not accept UT MANAGE or Support Chat manage alone —
	 * those must not become a Telegram-send shortcut.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function authorize_mutation( WP_REST_Request $request ): bool {
		$values  = $this->settings->get();
		$enabled = ! empty( $values['support_chat_adapter_enabled'] );
		if ( AdapterAvailability::Compatible !== $this->discovery->resolve( $enabled ) ) {
			return false;
		}

		/**
		 * Asserts an authenticated Support Chat → UT Contract caller.
		 *
		 * Default false. Set true only after the ADR-0007 signature has
		 * already been verified (see authorize_operation()). Never treat a
		 * bare capability check or rest_do_request context as sufficient.
		 *
		 * @since 0.16.0
		 *
		 * @param bool            $authorized Default false.
		 * @param WP_REST_Request $request    Request.
		 */
		return (bool) apply_filters( 'universal_telegram_support_chat_adapter_rest_authorized', false, $request );
	}

	/**
	 * Adapter status (diagnostics / handshake).
	 */
	public function handle_status(): WP_REST_Response {
		$values       = $this->settings->get();
		$enabled      = ! empty( $values['support_chat_adapter_enabled'] );
		$availability = $this->discovery->resolve( $enabled );

		return new WP_REST_Response(
			array(
				'ok'               => true,
				'adapter_enabled'  => $enabled,
				'availability'     => $availability->value,
				'contract_version' => ContractConstants::CONTRACT_VERSION_ID,
				'contract_pin_sha' => ContractConstants::CONTRACT_PIN_SHA,
				'contract_pin_url' => ContractConstants::CONTRACT_PIN_URL,
				'operational'      => AdapterAvailability::Compatible === $availability,
				'waiting_for'      => AdapterAvailability::Compatible === $availability
					? null
					: 'sc_m03_authenticated_contract_server',
			),
			200
		);
	}

	/**
	 * Handles ensure_channel_case.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function handle_ensure( WP_REST_Request $request ): WP_REST_Response {
		$gate = $this->require_compatible();
		if ( null !== $gate ) {
			return $gate;
		}

		$params            = $this->json_params( $request );
		$conversation_uuid = isset( $params['conversation_uuid'] ) && is_string( $params['conversation_uuid'] )
			? $params['conversation_uuid']
			: '';
		$idempotency_key   = isset( $params['idempotency_key'] ) && is_string( $params['idempotency_key'] )
			? $params['idempotency_key']
			: '';
		$summary           = isset( $params['summary'] ) && is_array( $params['summary'] ) ? $params['summary'] : array();

		if ( '' === $conversation_uuid || '' === $idempotency_key ) {
			return new WP_REST_Response(
				array(
					'ok'     => false,
					'status' => 'unavailable',
				),
				400
			);
		}

		$values  = $this->settings->get();
		$bot_id  = isset( $values['support_chat_adapter_bot_id'] ) ? (int) $values['support_chat_adapter_bot_id'] : 0;
		$dest_id = isset( $values['support_chat_adapter_destination_id'] ) ? (int) $values['support_chat_adapter_destination_id'] : 0;
		$parent  = $dest_id > 0 ? $this->destinations->find( $dest_id ) : null;

		if ( $bot_id <= 0 || null === $parent ) {
			return new WP_REST_Response(
				array(
					'ok'               => true,
					'channel_case_ref' => '',
					'status'           => 'unavailable',
				),
				200
			);
		}

		$result = $this->ensure->ensure( $conversation_uuid, $idempotency_key, $bot_id, $parent, $summary );

		return new WP_REST_Response(
			array(
				'ok'               => true,
				'channel_case_ref' => $result['channel_case_ref'],
				'status'           => $result['status'],
			),
			200
		);
	}

	/**
	 * Handles notify_operators.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function handle_notify( WP_REST_Request $request ): WP_REST_Response {
		$gate = $this->require_compatible();
		if ( null !== $gate ) {
			return $gate;
		}

		$params  = $this->json_params( $request );
		$ref     = isset( $params['channel_case_ref'] ) && is_string( $params['channel_case_ref'] ) ? $params['channel_case_ref'] : '';
		$key     = isset( $params['idempotency_key'] ) && is_string( $params['idempotency_key'] ) ? $params['idempotency_key'] : '';
		$kind    = isset( $params['kind'] ) && is_string( $params['kind'] ) ? $params['kind'] : 'attention';
		$summary = isset( $params['summary'] ) && is_string( $params['summary'] ) ? $params['summary'] : '';

		if ( '' === $ref || '' === $key ) {
			return new WP_REST_Response( array( 'ok' => false ), 400 );
		}

		$result = $this->notify->notify( $ref, $key, $kind, $summary );

		return new WP_REST_Response(
			array(
				'ok'     => $result['ok'],
				'reused' => $result['reused'],
				'reason' => $result['reason'],
			),
			$result['ok'] ? 200 : 503
		);
	}

	/**
	 * Handles deliver_transcript_backfill.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function handle_backfill( WP_REST_Request $request ): WP_REST_Response {
		$gate = $this->require_compatible();
		if ( null !== $gate ) {
			return $gate;
		}

		$params   = $this->json_params( $request );
		$ref      = isset( $params['channel_case_ref'] ) && is_string( $params['channel_case_ref'] ) ? $params['channel_case_ref'] : '';
		$messages = isset( $params['messages'] ) && is_array( $params['messages'] ) ? $params['messages'] : array();

		if ( '' === $ref ) {
			return new WP_REST_Response( array( 'ok' => false ), 400 );
		}

		$result = $this->backfill->backfill( $ref, $messages );

		return new WP_REST_Response( $result, $result['ok'] ? 200 : 503 );
	}

	/**
	 * Handles deliver_message.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function handle_deliver( WP_REST_Request $request ): WP_REST_Response {
		$gate = $this->require_compatible();
		if ( null !== $gate ) {
			return $gate;
		}

		$params = $this->json_params( $request );
		$ref    = isset( $params['channel_case_ref'] ) && is_string( $params['channel_case_ref'] ) ? $params['channel_case_ref'] : '';
		$key    = isset( $params['idempotency_key'] ) && is_string( $params['idempotency_key'] ) ? $params['idempotency_key'] : '';
		$body   = isset( $params['body'] ) && is_string( $params['body'] ) ? $params['body'] : '';
		$attr   = isset( $params['attribution'] ) && is_string( $params['attribution'] ) ? $params['attribution'] : '';

		if ( '' === $ref || '' === $key || '' === $body ) {
			return new WP_REST_Response( array( 'ok' => false ), 400 );
		}

		$result = $this->deliver->deliver( $ref, $key, $body, $attr );

		return new WP_REST_Response(
			array(
				'ok'     => $result['ok'],
				'reused' => $result['reused'],
				'reason' => $result['reason'],
			),
			$result['ok'] ? 200 : 503
		);
	}

	/**
	 * Returns a 503 response when the adapter is not Contract-compatible.
	 *
	 * @return WP_REST_Response|null
	 */
	private function require_compatible(): ?WP_REST_Response {
		$values  = $this->settings->get();
		$enabled = ! empty( $values['support_chat_adapter_enabled'] );
		$state   = $this->discovery->resolve( $enabled );

		if ( AdapterAvailability::Compatible === $state ) {
			return null;
		}

		return new WP_REST_Response(
			array(
				'ok'           => false,
				'status'       => 'unavailable',
				'availability' => $state->value,
			),
			503
		);
	}

	/**
	 * Reads JSON or form parameters from the request.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return array<string, mixed>
	 */
	private function json_params( WP_REST_Request $request ): array {
		$json = $request->get_json_params();
		if ( array() !== $json ) {
			return $json;
		}

		return $request->get_params();
	}
}
