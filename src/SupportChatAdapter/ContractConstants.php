<?php
/**
 * Pinned Support Channel Contract v1 constants.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\SupportChatAdapter;

/**
 * Immutable pin to Support Chat ADR-0005 Contract v1. Do not duplicate the
 * full contract text here — only the version id and pin references.
 */
final class ContractConstants {

	public const CONTRACT_VERSION_ID = 'support-channel-contract/v1';

	public const CONTRACT_PIN_SHA = 'dff2730e24b7d3f70f15f706305e12e14fdcc6c8';

	public const CONTRACT_PIN_URL = 'https://github.com/magpern/universal-support-chat/blob/dff2730e24b7d3f70f15f706305e12e14fdcc6c8/docs/adr/0005-canonical-support-channel-contract-v1.md';

	/**
	 * ADR-0007's fixed authentication profile identifier, pinned exactly
	 * (SHA `8ee396d8b8edcbf526797c0a1f5741f3842df57a`,
	 * https://github.com/magpern/universal-support-chat/blob/8ee396d8b8edcbf526797c0a1f5741f3842df57a/docs/adr/0007-contract-v1-mutual-signed-adapter-authentication-profile.md).
	 */
	public const AUTH_PROFILE_ID = 'support-channel-contract-auth/v1';

	/**
	 * This plugin's own Contract v1 identity (ADR-0007 §1).
	 */
	public const SELF_ID = 'universal-telegram';

	/**
	 * Support Chat's Contract v1 identity (ADR-0007 §1).
	 */
	public const PEER_ID = 'universal-support-chat';

	/**
	 * Support Chat's own manage capability. Pairing (ADR-0007 §2) requires
	 * the acting administrator to hold both this plugin's MANAGE capability
	 * and this one — never either alone.
	 */
	public const SUPPORT_CHAT_MANAGE_CAPABILITY = 'universal_support_chat_manage';

	public const SC_DISCOVERY_ROUTE = '/universal-support-chat/v1/channel-contract';

	public const UT_REST_NAMESPACE = 'universal-telegram/v1';

	public const UT_REST_PREFIX = '/support-chat';

	/**
	 * Adapter (this plugin) → Support Chat Contract v1 operations, exactly
	 * as SC-M03 work package 0's `ContractOperations::ADAPTER_TO_SUPPORT_CHAT`
	 * defines them. `update_operator_presence` is a defined ADR-0005 §5
	 * operation but is deliberately absent here and from Support Chat's own
	 * allow-list: SC-M03 work package 0 has no Availability-boundary
	 * storage yet (not authorized until SC-M06), so Support Chat never
	 * advertises or pairs it, and this client must not sign a call for an
	 * operation the peer can never accept.
	 *
	 * @return array<int, string>
	 */
	public static function adapter_to_support_chat_operations(): array {
		return array(
			'ingest_operator_reply',
			'claim',
			'release',
			'resolve',
			'reopen',
			'update_assignment',
			'report_channel_unavailable',
			'report_delivery_failure',
		);
	}

	/**
	 * Support Chat → adapter (this plugin) Contract v1 operations this
	 * plugin verifies and dispatches.
	 *
	 * @return array<int, string>
	 */
	public static function support_chat_to_adapter_operations(): array {
		return array(
			'ensure_channel_case',
			'notify_operators',
			'deliver_transcript_backfill',
			'deliver_message',
		);
	}

	/**
	 * Operations Support Chat must advertise before UT treats the channel
	 * as Compatible (Adapter M1 required set). Support Chat's discovery
	 * (ContractDiscovery) only ever advertises the subset of
	 * `adapter_to_support_chat_operations()` the currently paired peer is
	 * permitted to call — it never advertises the Support-Chat-to-adapter
	 * operations, since those describe what Support Chat sends, not what it
	 * accepts. Compatibility therefore requires exactly this adapter→SC set.
	 *
	 * @return array<int, string>
	 */
	public static function required_operations(): array {
		return self::adapter_to_support_chat_operations();
	}

	/**
	 * Whether every operation in the list is a real Support-Chat-to-adapter
	 * Contract v1 operation this plugin can pair a peer to call. Used to
	 * validate a peer's permitted-operation allow-list at pairing time
	 * (ADR-0007 §2) — a peer can never be granted an operation name
	 * Contract v1 does not define for that direction.
	 *
	 * @param array<int, mixed> $operations Candidate operation names.
	 */
	public static function is_valid_peer_allow_list( array $operations ): bool {
		if ( array() === $operations ) {
			return false;
		}

		foreach ( $operations as $operation ) {
			if ( ! is_string( $operation ) || ! in_array( $operation, self::support_chat_to_adapter_operations(), true ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Not instantiable.
	 */
	private function __construct() {}
}
