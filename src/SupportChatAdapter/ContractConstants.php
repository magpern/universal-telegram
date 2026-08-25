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

	public const SC_DISCOVERY_ROUTE = '/universal-support-chat/v1/channel-contract';

	public const UT_REST_NAMESPACE = 'universal-telegram/v1';

	public const UT_REST_PREFIX = '/support-chat';

	/**
	 * Operations Support Chat must advertise before UT treats the channel as
	 * Compatible (Adapter M1 required set).
	 *
	 * @return array<int, string>
	 */
	public static function required_operations(): array {
		return array(
			'ensure_channel_case',
			'notify_operators',
			'deliver_transcript_backfill',
			'deliver_message',
			'ingest_operator_reply',
			'claim',
			'release',
			'resolve',
			'reopen',
			'update_assignment',
			'update_operator_presence',
			'report_channel_unavailable',
			'report_delivery_failure',
		);
	}

	/**
	 * Not instantiable.
	 */
	private function __construct() {}
}
