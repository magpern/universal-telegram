<?php
/**
 * SC-M03 final-cutover run states.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Migration;

/**
 * The states of one cutover run (docs/adr/0042 §1): a narrow orchestration
 * layer above (never replacing) `QuiescenceState`. There is no `not_started`
 * case — that is simply the absence of any non-terminal run row
 * (`CutoverRunRepository::find_active()` returning null), not a persisted
 * value.
 *
 * `activating` is persisted at rest, not only transiently mid-transaction:
 * `activate` stamps `prepared → activating` before its per-candidate saga
 * begins, so a crash mid-saga leaves a durable `activating` row a later
 * `activate` invocation for the same run resumes against (comparing the
 * cohort to `cutover_activation_audit`'s own rows), rather than starting a
 * second, indistinguishable saga blindly.
 */
enum CutoverState: string {
	case PREPARED          = 'prepared';
	case ACTIVATING        = 'activating';
	case ACTIVATED         = 'activated';
	case ACTIVATION_FAILED = 'activation_failed';
	case COMPLETE          = 'complete';

	/**
	 * Whether this run is still "in progress" — neither a terminal success
	 * (`complete`) nor a terminal failure (`activation_failed`) — and
	 * therefore blocks a new `begin()` from starting a second, concurrent
	 * run (docs/adr/0042 §1: only one run may be active at a time).
	 */
	public function is_open(): bool {
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- $this is fully valid inside a backed enum's own instance method under PHP 8.1+ (each case is its own singleton instance); this sniff version does not recognize enum methods as object context, a known false positive (see ConditionOperator::matches()).
		return self::COMPLETE !== $this && self::ACTIVATION_FAILED !== $this;
	}
}
