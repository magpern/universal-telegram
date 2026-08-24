<?php
/**
 * Deterministic notification rule evaluation.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Automations;

use Throwable;
use UniversalTelegram\Automations\Digest\DigestEligibility;
use UniversalTelegram\Events\EventEnvelope;
use UniversalTelegram\Events\Registry;

/**
 * Called only by Events\EventDispatcher::handle(), immediately after the
 * history-projection write. Loads every enabled rule for the event's own
 * type in the repository's own deterministic order (priority ASC, id
 * ASC — M02 plan §7.3), and evaluates each rule's conditions inside its
 * own try/catch so one rule's failure never affects another. A single
 * event may match, and independently trigger, more than one rule.
 *
 * Not declared final: on_matched()/on_rejected() are extension points
 * wired to real dispatch-log/dispatch behavior in WP7; tests/unit/
 * Automations/RuleEvaluatorTest.php substitutes overrides that record
 * calls instead.
 */
class RuleEvaluator {

	public const LAST_EVALUATION_ERROR_CODE_OPTION = 'universal_telegram_automations_last_evaluation_error_code';

	/**
	 * The dispatch-log reason code recorded for a rule whose event type is
	 * suppressed by an active visitor digest (M11A,
	 * docs/plans/m11a-visitor-activity-digests-plan-v1.md §3.1) — the same
	 * REJECTED result/reason-code convention every other rejection cause
	 * already uses (invalid_condition_field, condition_not_matched, etc.),
	 * not a new DispatchLogResult row-level outcome.
	 */
	public const SUPPRESSED_BY_DIGEST_REASON_CODE = 'skipped_suppressed_by_digest';

	/**
	 * Constructor.
	 *
	 * @param NotificationRuleRepository $rules              Supplies each event type's own enabled rules, deterministically ordered.
	 * @param Registry                   $registry           Supplies each event type's allowed variable fields.
	 * @param DispatchLogRepository      $dispatch_log       Records a rejected outcome for a non-matching rule.
	 * @param NotificationDispatcher     $dispatcher         Executes the full dispatch sequence for a matched rule.
	 * @param DigestEligibility|null     $digest_eligibility Suppresses digest-eligible visitor event types while active (M11A). Nullable only for pre-M11A test doubles that construct this class directly; production wiring always supplies it.
	 */
	public function __construct(
		private readonly NotificationRuleRepository $rules,
		private readonly Registry $registry,
		private readonly DispatchLogRepository $dispatch_log,
		private readonly NotificationDispatcher $dispatcher,
		private readonly ?DigestEligibility $digest_eligibility = null
	) {}

	/**
	 * Evaluates every enabled rule registered for the event's own type.
	 *
	 * While an active visitor digest (M11A) suppresses this event's type,
	 * no rule for it is evaluated at all — each matching enabled rule is
	 * recorded rejected with SUPPRESSED_BY_DIGEST_REASON_CODE instead, and
	 * digest aggregation (Events\EventDispatcher) takes over for that
	 * event. When the digest is disabled, or enabled with an invalid
	 * target, this method behaves exactly as it did before M11A.
	 *
	 * @param EventEnvelope $event The event to evaluate rules against.
	 */
	public function evaluate( EventEnvelope $event ): void {
		$rules = $this->rules->for_event_type( $event->event_type(), true );

		if ( $this->is_suppressed_by_digest( $event->event_type() ) ) {
			foreach ( $rules as $rule ) {
				// Routed through the same on_rejected() extension point every
				// other rejection cause already uses (not a direct
				// dispatch_log call), so RuleSimulator's own no-op override
				// applies here too — a simulated preview never writes to
				// notification_dispatch_log, suppression included.
				$this->on_rejected( $rule, $event, self::SUPPRESSED_BY_DIGEST_REASON_CODE );
			}
			return;
		}

		foreach ( $rules as $rule ) {
			try {
				$this->evaluate_rule( $rule, $event );
			} catch ( Throwable $exception ) {
				// One rule's exception never stops evaluation of the
				// remaining rules (M02 plan §7.3). Recorded as a fixed,
				// non-message-carrying diagnostic code only — never a raw
				// exception message. Guarded so this class remains
				// WordPress-free and unit-testable (tests/unit/Automations/
				// RuleEvaluatorTest.php carries no WordPress bootstrap).
				if ( function_exists( 'update_option' ) ) {
					update_option( self::LAST_EVALUATION_ERROR_CODE_OPTION, 'rule_evaluation_exception', false );
				}
				continue;
			}
		}
	}

	/**
	 * Evaluates one rule's conditions and delegates to the matched/rejected
	 * extension point.
	 *
	 * @param NotificationRule $rule  The rule to evaluate.
	 * @param EventEnvelope    $event The event occurrence.
	 */
	private function evaluate_rule( NotificationRule $rule, EventEnvelope $event ): void {
		$reason = $this->rejection_reason( $rule, $event );

		if ( null !== $reason ) {
			$this->on_rejected( $rule, $event, $reason );
			return;
		}

		$this->on_matched( $rule, $event );
	}

	/**
	 * Evaluates every clause; returns null if the rule matches, or a fixed
	 * rejection reason code otherwise. An unknown field or operator makes
	 * the rule evaluate to "rejected — invalid configuration" (M02 plan
	 * §7.2, §7.3).
	 *
	 * @param NotificationRule $rule  The rule to evaluate.
	 * @param EventEnvelope    $event The event occurrence.
	 *
	 * @return string|null
	 */
	private function rejection_reason( NotificationRule $rule, EventEnvelope $event ): ?string {
		$allowed_fields = $this->registry->allowed_variable_fields_for( $event->event_type() );

		foreach ( $rule->conditions() as $clause ) {
			$field = $clause['field'] ?? null;

			if ( ! is_string( $field ) || ! in_array( $field, $allowed_fields, true ) ) {
				return 'invalid_condition_field';
			}

			$operator = ConditionOperator::tryFrom( (string) ( $clause['operator'] ?? '' ) );

			if ( null === $operator ) {
				return 'invalid_condition_operator';
			}

			$actual = $event->value_at( $field );

			if ( ! $operator->matches( $actual, $clause['value'] ?? null ) ) {
				return 'condition_not_matched';
			}
		}

		return null;
	}

	/**
	 * Called when a rule's conditions all matched: delegates the full
	 * idempotent dispatch sequence to NotificationDispatcher.
	 *
	 * @param NotificationRule $rule  The matched rule.
	 * @param EventEnvelope    $event The event occurrence.
	 */
	protected function on_matched( NotificationRule $rule, EventEnvelope $event ): void {
		$this->dispatcher->dispatch( $rule, $event );
	}

	/**
	 * Called when a rule's conditions did not match, or its own
	 * configuration was invalid: records the rejected outcome via the
	 * same atomic claim-or-reject mechanism.
	 *
	 * @param NotificationRule $rule        The rejected rule.
	 * @param EventEnvelope    $event       The event occurrence.
	 * @param string           $reason_code The fixed rejection reason code.
	 */
	protected function on_rejected( NotificationRule $rule, EventEnvelope $event, string $reason_code ): void {
		$this->dispatch_log->record_rejected( $rule->id(), $event->event_id(), $reason_code );
	}

	/**
	 * Whether the given event type is currently suppressed by an active
	 * visitor digest: it is one of the seven digest-eligible types AND the
	 * digest is enabled with a currently valid target (M11A §3.1).
	 *
	 * @param string $event_type The event type to check.
	 *
	 * @return bool
	 */
	private function is_suppressed_by_digest( string $event_type ): bool {
		if ( null === $this->digest_eligibility ) {
			return false;
		}

		if ( ! in_array( $event_type, DigestEligibility::SUPPRESSED_EVENT_TYPES, true ) ) {
			return false;
		}

		return $this->digest_eligibility->is_active();
	}
}
