<?php
/**
 * Support Chat Contract client (adapter → Support Chat).
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\SupportChatAdapter\Inbound;

use UniversalTelegram\SupportChatAdapter\ContractConstants;
use WP_REST_Request;

/**
 * Calls Support Chat Contract v1 operations via REST. Fail-closed when SC
 * routes are absent (404) or return errors — never writes SC SQL.
 */
final class SupportChatContractClient {

	public const SC_NAMESPACE = 'universal-support-chat/v1';

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
		return $this->post(
			'/channel/ingest_operator_reply',
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
		return $this->lifecycle( 'claim', $channel_case_ref, $operator_user_id, $idempotency_key );
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
		return $this->lifecycle( 'release', $channel_case_ref, $operator_user_id, $idempotency_key );
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
		return $this->lifecycle( 'resolve', $channel_case_ref, $operator_user_id, $idempotency_key );
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
		return $this->lifecycle( 'reopen', $channel_case_ref, $operator_user_id, $idempotency_key );
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
		return $this->post(
			'/channel/report_channel_unavailable',
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
		return $this->post(
			'/channel/report_delivery_failure',
			array(
				'channel_case_ref' => $channel_case_ref,
				'idempotency_key'  => $idempotency_key,
				'reason_code'      => $reason_code,
			)
		);
	}

	/**
	 * Posts a named lifecycle operation to Support Chat.
	 *
	 * @param string $operation        Lifecycle operation name.
	 * @param string $channel_case_ref Opaque binding UUID.
	 * @param int    $operator_user_id Operator WP user id.
	 * @param string $idempotency_key  Idempotency key.
	 *
	 * @return array{ok: bool, status: int, reason: string|null}
	 */
	private function lifecycle( string $operation, string $channel_case_ref, int $operator_user_id, string $idempotency_key ): array {
		return $this->post(
			'/channel/' . $operation,
			array(
				'channel_case_ref' => $channel_case_ref,
				'operator_user_id' => $operator_user_id,
				'idempotency_key'  => $idempotency_key,
			)
		);
	}

	/**
	 * Performs an authenticated Support Chat Contract POST.
	 *
	 * @param string               $path Relative path under SC namespace.
	 * @param array<string, mixed> $body JSON body.
	 *
	 * @return array{ok: bool, status: int, reason: string|null}
	 */
	private function post( string $path, array $body ): array {
		if ( ! function_exists( 'rest_do_request' ) ) {
			return array(
				'ok'     => false,
				'status' => 0,
				'reason' => 'rest_unavailable',
			);
		}

		$request = new WP_REST_Request( 'POST', '/' . self::SC_NAMESPACE . $path );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			$error  = $response->as_error();
			$status = 500;
			if ( $error instanceof \WP_Error ) {
				$data = $error->get_error_data();
				if ( is_array( $data ) && isset( $data['status'] ) ) {
					$status = (int) $data['status'];
				}
			}

			return array(
				'ok'     => false,
				'status' => $status,
				'reason' => 'request_error',
			);
		}

		$status = $response->get_status();
		if ( $status < 200 || $status >= 300 ) {
			return array(
				'ok'     => false,
				'status' => $status,
				'reason' => 404 === $status ? 'sc_route_absent' : 'sc_rejected',
			);
		}

		return array(
			'ok'     => true,
			'status' => $status,
			'reason' => null,
		);
	}
}
