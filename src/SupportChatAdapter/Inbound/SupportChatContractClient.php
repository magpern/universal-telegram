<?php
/**
 * Support Chat Contract client (adapter → Support Chat).
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\SupportChatAdapter\Inbound;

/**
 * Adapter → Support Chat Contract calls.
 *
 * Contract v1 requires authenticated, capability-checked calls. A bare
 * `rest_do_request()` in the current WordPress user context is **not** that
 * authentication boundary. Until Support Chat SC-M03 publishes an
 * authenticated Contract server (and UT consumes that mechanism), every
 * lifecycle/mutation call fails closed as unavailable. This class never
 * writes Support Chat SQL and never invents a shared-secret or public bypass.
 */
final class SupportChatContractClient {

	public const SC_NAMESPACE = 'universal-support-chat/v1';

	public const UNAVAILABLE_REASON = 'sc_authenticated_contract_unavailable';

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
		unset( $channel_case_ref, $idempotency_key, $plaintext_body, $operator_user_id, $remote_meta );
		return $this->unavailable();
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
		unset( $channel_case_ref, $operator_user_id, $idempotency_key );
		return $this->unavailable();
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
		unset( $channel_case_ref, $operator_user_id, $idempotency_key );
		return $this->unavailable();
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
		unset( $channel_case_ref, $operator_user_id, $idempotency_key );
		return $this->unavailable();
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
		unset( $channel_case_ref, $operator_user_id, $idempotency_key );
		return $this->unavailable();
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
		unset( $channel_case_ref, $reason_code );
		return $this->unavailable();
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
		unset( $channel_case_ref, $idempotency_key, $reason_code );
		return $this->unavailable();
	}

	/**
	 * Fail-closed response until SC-M03 authenticated Contract server exists.
	 *
	 * @return array{ok: bool, status: int, reason: string|null}
	 */
	private function unavailable(): array {
		return array(
			'ok'     => false,
			'status' => 503,
			'reason' => self::UNAVAILABLE_REASON,
		);
	}
}
