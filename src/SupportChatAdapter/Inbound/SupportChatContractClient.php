<?php
/**
 * Support Chat Contract client (adapter → Support Chat).
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\SupportChatAdapter\Inbound;

use UniversalTelegram\SupportChatAdapter\AdapterAvailability;
use UniversalTelegram\SupportChatAdapter\Auth\OwnKeyManager;
use UniversalTelegram\SupportChatAdapter\Auth\PeerRepository;
use UniversalTelegram\SupportChatAdapter\Auth\SignatureSigner;
use UniversalTelegram\SupportChatAdapter\ContractConstants;
use UniversalTelegram\SupportChatAdapter\DiscoveryClient;
use WP_REST_Request;

/**
 * Adapter → Support Chat Contract calls.
 *
 * Every call is an ADR-0007 §3 authenticated, Ed25519-signed request built
 * from this plugin's own key pair (SupportChatAdapter\Auth\OwnKeyManager)
 * and dispatched in-process via `rest_do_request()`, exactly as this
 * plugin's own `DiscoveryClient` already dispatches Support Chat's
 * discovery route — both plugins run on the same WordPress install.
 *
 * Fails closed (never sends a request) when: the adapter is not paired
 * with Support Chat, the paired peer is disabled/revoked/expired, this
 * plugin's own signing key or CredentialVault is unavailable, or discovery
 * does not currently advertise every Adapter M1 required operation under
 * the pinned Contract version and auth profile. This class never writes
 * Support Chat SQL and never accepts an unsigned or capability-only bypass.
 */
final class SupportChatContractClient {

	public const SC_NAMESPACE = 'universal-support-chat/v1';

	public const UNAVAILABLE_REASON = 'sc_authenticated_contract_unavailable';

	public const REASON_NOT_PAIRED             = 'sc_contract_not_paired';
	public const REASON_DISCOVERY_INCOMPATIBLE = 'sc_contract_discovery_incompatible';
	public const REASON_SIGNING_UNAVAILABLE    = 'sc_contract_signing_unavailable';
	public const REASON_UNSUPPORTED_OPERATION  = 'sc_contract_unsupported_operation';
	public const REASON_TRANSPORT_FAILED       = 'sc_contract_transport_failed';

	/**
	 * Constructor. All collaborators are optional so existing call sites
	 * (`new SupportChatContractClient()`) keep working fail-closed until
	 * Plugin.php wires the real collaborators.
	 *
	 * @param PeerRepository|null  $peers     Local record of the paired Support Chat peer.
	 * @param OwnKeyManager|null   $own_key   This plugin's own key pair.
	 * @param DiscoveryClient|null $discovery Support Chat Contract discovery.
	 * @param SignatureSigner|null $signer    Outbound request signer.
	 * @param bool                 $adapter_enabled Settings flag.
	 */
	public function __construct(
		private readonly ?PeerRepository $peers = null,
		private readonly ?OwnKeyManager $own_key = null,
		private readonly ?DiscoveryClient $discovery = null,
		private readonly ?SignatureSigner $signer = null,
		private readonly bool $adapter_enabled = false
	) {}

	/**
	 * Ingests an operator reply into Support Chat.
	 *
	 * @param string               $channel_case_ref Opaque binding UUID.
	 * @param string               $idempotency_key  Remote-update idempotency key.
	 * @param string               $plaintext_body   Reply body (in memory).
	 * @param int                  $operator_user_id Mapped WP operator user id.
	 * @param array<string, mixed> $remote_meta      Non-secret remote metadata.
	 *
	 * @return array{ok: bool, status: int, reason: string|null}
	 */
	public function ingest_operator_reply(
		string $channel_case_ref,
		string $idempotency_key,
		string $plaintext_body,
		int $operator_user_id,
		array $remote_meta = array()
	): array {
		return $this->call(
			'ingest_operator_reply',
			array(
				'channel_case_ref' => $channel_case_ref,
				'idempotency_key'  => $idempotency_key,
				'body'             => $plaintext_body,
				'operator_user_id' => $operator_user_id,
				'remote_meta'      => $remote_meta,
			)
		);
	}

	/**
	 * Lifecycle claim.
	 *
	 * @param string $channel_case_ref Opaque binding UUID.
	 * @param int    $operator_user_id Operator WP user id.
	 * @param string $idempotency_key  Idempotency key.
	 *
	 * @return array{ok: bool, status: int, reason: string|null}
	 */
	public function claim( string $channel_case_ref, int $operator_user_id, string $idempotency_key ): array {
		return $this->lifecycle_call( 'claim', $channel_case_ref, $operator_user_id, $idempotency_key );
	}

	/**
	 * Lifecycle release.
	 *
	 * @param string $channel_case_ref Opaque binding UUID.
	 * @param int    $operator_user_id Operator WP user id.
	 * @param string $idempotency_key  Idempotency key.
	 *
	 * @return array{ok: bool, status: int, reason: string|null}
	 */
	public function release( string $channel_case_ref, int $operator_user_id, string $idempotency_key ): array {
		return $this->lifecycle_call( 'release', $channel_case_ref, $operator_user_id, $idempotency_key );
	}

	/**
	 * Lifecycle resolve.
	 *
	 * @param string $channel_case_ref Opaque binding UUID.
	 * @param int    $operator_user_id Operator WP user id.
	 * @param string $idempotency_key  Idempotency key.
	 *
	 * @return array{ok: bool, status: int, reason: string|null}
	 */
	public function resolve( string $channel_case_ref, int $operator_user_id, string $idempotency_key ): array {
		return $this->lifecycle_call( 'resolve', $channel_case_ref, $operator_user_id, $idempotency_key );
	}

	/**
	 * Lifecycle reopen.
	 *
	 * @param string $channel_case_ref Opaque binding UUID.
	 * @param int    $operator_user_id Operator WP user id.
	 * @param string $idempotency_key  Idempotency key.
	 *
	 * @return array{ok: bool, status: int, reason: string|null}
	 */
	public function reopen( string $channel_case_ref, int $operator_user_id, string $idempotency_key ): array {
		return $this->lifecycle_call( 'reopen', $channel_case_ref, $operator_user_id, $idempotency_key );
	}

	/**
	 * Updates the Support Chat conversation's assignee.
	 *
	 * @param string $channel_case_ref Opaque binding UUID.
	 * @param int    $operator_user_id Newly assigned operator WP user id.
	 * @param string $idempotency_key  Idempotency key.
	 *
	 * @return array{ok: bool, status: int, reason: string|null}
	 */
	public function update_assignment( string $channel_case_ref, int $operator_user_id, string $idempotency_key ): array {
		return $this->lifecycle_call( 'update_assignment', $channel_case_ref, $operator_user_id, $idempotency_key );
	}

	/**
	 * Reports channel unavailable for a binding.
	 *
	 * @param string $channel_case_ref Opaque binding UUID.
	 * @param string $reason_code      Fixed reason code.
	 *
	 * @return array{ok: bool, status: int, reason: string|null}
	 */
	public function report_channel_unavailable( string $channel_case_ref, string $reason_code ): array {
		return $this->call(
			'report_channel_unavailable',
			array(
				'channel_case_ref' => $channel_case_ref,
				'reason_code'      => $reason_code,
			)
		);
	}

	/**
	 * Reports outbound delivery failure.
	 *
	 * @param string $channel_case_ref Opaque binding UUID.
	 * @param string $idempotency_key  Original deliver key.
	 * @param string $reason_code      Fixed reason code.
	 *
	 * @return array{ok: bool, status: int, reason: string|null}
	 */
	public function report_delivery_failure( string $channel_case_ref, string $idempotency_key, string $reason_code ): array {
		return $this->call(
			'report_delivery_failure',
			array(
				'channel_case_ref' => $channel_case_ref,
				'idempotency_key'  => $idempotency_key,
				'reason_code'      => $reason_code,
			)
		);
	}

	/**
	 * Shared body shape for the four operator-user-id lifecycle operations.
	 *
	 * @param string $operation        Contract operation name.
	 * @param string $channel_case_ref Opaque binding UUID.
	 * @param int    $operator_user_id Operator WP user id.
	 * @param string $idempotency_key  Idempotency key.
	 *
	 * @return array{ok: bool, status: int, reason: string|null}
	 */
	private function lifecycle_call( string $operation, string $channel_case_ref, int $operator_user_id, string $idempotency_key ): array {
		return $this->call(
			$operation,
			array(
				'channel_case_ref' => $channel_case_ref,
				'operator_user_id' => $operator_user_id,
				'idempotency_key'  => $idempotency_key,
			)
		);
	}

	/**
	 * Builds, signs, and dispatches one authenticated Contract v1 call.
	 * Fails closed at every gate before a request is ever signed or sent.
	 *
	 * @param string               $operation Contract operation name.
	 * @param array<string, mixed> $body      Request body (JSON-encoded exactly once).
	 *
	 * @return array{ok: bool, status: int, reason: string|null}
	 */
	private function call( string $operation, array $body ): array {
		if ( ! in_array( $operation, ContractConstants::adapter_to_support_chat_operations(), true ) ) {
			return $this->unavailable( self::REASON_UNSUPPORTED_OPERATION );
		}

		if ( null === $this->peers || null === $this->own_key || null === $this->discovery || null === $this->signer ) {
			return $this->unavailable( self::UNAVAILABLE_REASON );
		}

		if ( ! $this->adapter_enabled ) {
			return $this->unavailable( self::REASON_NOT_PAIRED );
		}

		$peer = $this->peers->find_by_peer_id( ContractConstants::PEER_ID );
		if ( null === $peer || ! $peer->is_usable() ) {
			return $this->unavailable( self::REASON_NOT_PAIRED );
		}

		// Discovery must currently advertise every Adapter M1 required
		// operation under the pinned Contract version and auth profile
		// before any call is even attempted — never trust a stale or
		// partially-compatible handshake.
		if ( AdapterAvailability::Compatible !== $this->discovery->resolve( true ) ) {
			return $this->unavailable( self::REASON_DISCOVERY_INCOMPATIBLE );
		}

		if ( ! function_exists( 'rest_do_request' ) ) {
			return $this->unavailable( self::REASON_TRANSPORT_FAILED );
		}

		$raw_body = (string) wp_json_encode( $body );
		$route    = '/' . self::SC_NAMESPACE . '/contract/' . $operation;

		$headers = $this->signer->sign( 'POST', $route, $raw_body, $operation );
		if ( null === $headers ) {
			return $this->unavailable( self::REASON_SIGNING_UNAVAILABLE );
		}

		$request = new WP_REST_Request( 'POST', $route );
		$request->set_header( 'Content-Type', 'application/json' );
		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}
		$request->set_body( $raw_body );

		$response = rest_do_request( $request );
		$status   = $response->get_status();
		$data     = $response->get_data();
		$ok       = ! $response->is_error() && $status >= 200 && $status < 300
			&& is_array( $data ) && ! empty( $data['ok'] );

		if ( $ok ) {
			$this->peers->touch_last_used( ContractConstants::PEER_ID );

			return array(
				'ok'     => true,
				'status' => $status,
				'reason' => null,
			);
		}

		$reason = is_array( $data ) && isset( $data['reason'] ) && is_string( $data['reason'] )
			? $data['reason']
			: self::REASON_TRANSPORT_FAILED;

		return array(
			'ok'     => false,
			'status' => $status > 0 ? $status : 503,
			'reason' => $reason,
		);
	}

	/**
	 * Fail-closed response for a specific gate.
	 *
	 * @param string $reason Stable, non-sensitive reason code.
	 *
	 * @return array{ok: bool, status: int, reason: string|null}
	 */
	private function unavailable( string $reason = self::UNAVAILABLE_REASON ): array {
		return array(
			'ok'     => false,
			'status' => 503,
			'reason' => $reason,
		);
	}
}
