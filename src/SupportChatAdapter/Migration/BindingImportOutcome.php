<?php
/**
 * Typed outcome vocabulary for LegacyBindingImportServiceV1::import_batch().
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\SupportChatAdapter\Migration;

/**
 * The subset of Support Chat ADR-0009 §4's outcome vocabulary this
 * repository's own service is responsible for determining (the structural
 * eligibility outcomes §2 items 2-6 are Support Chat-side map-row checks
 * performed before a candidate ever reaches this repository). Terminal
 * outcomes are §4's "manual review" or "idempotent success" cases; retryable
 * outcomes are auto-reselected by Support Chat's next ordinary rerun.
 */
final class BindingImportOutcome {

	public const CREATED = 'created';

	public const SKIP_TOPIC_STATE_CHANGED = 'binding_skip_topic_state_changed_since_migration';

	public const RETRY_UT_UNAVAILABLE_OR_INDETERMINATE = 'binding_retry_ut_unavailable_or_indeterminate';

	public const SKIP_ALREADY_BOUND = 'binding_skip_already_bound';

	public const CONFLICT_EXISTING_MISMATCHED = 'binding_conflict_existing_mismatched';

	public const CONFLICT_EXISTING_ACTIVE = 'binding_conflict_existing_active';

	public const CONFLICT_EXISTING_STATUS_UNRESOLVED = 'binding_conflict_existing_status_unresolved';

	public const RETRY_NOT_QUIESCENT = 'binding_retry_not_quiescent';

	public const RETRY_TRANSIENT_ERROR = 'binding_retry_transient_error';

	/**
	 * Outcomes that are terminal (Support Chat writes a non-NULL
	 * binding_status for these; ADR-0009 §4). Every other constant above is
	 * retryable.
	 *
	 * @return array<int, string>
	 */
	public static function terminal(): array {
		return array(
			self::CREATED,
			self::SKIP_TOPIC_STATE_CHANGED,
			self::SKIP_ALREADY_BOUND,
			self::CONFLICT_EXISTING_MISMATCHED,
			self::CONFLICT_EXISTING_ACTIVE,
			self::CONFLICT_EXISTING_STATUS_UNRESOLVED,
		);
	}
}
