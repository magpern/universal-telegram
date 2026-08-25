<?php
/**
 * One condition clause's evaluated result.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Automations;

/**
 * The structured, honest outcome of evaluating a single NotificationRule
 * condition clause against one EventEnvelope (M08.2 plan §1) — carries
 * enough to explain a non-match in plain language (the field's presence,
 * its actual value, whether the field/operator were even valid) without
 * any caller re-running EventEnvelope::value_at()/ConditionOperator::
 * matches() itself. `$field`/`$operator`/`$expected_value`/`$actual_value`
 * are raw, technical values — safe for RuleEvaluator's own internal use
 * and for a caller that already holds a friendly-label mapping for them,
 * never for direct display to a normal administrator.
 */
final class ConditionClauseResult {

	/**
	 * Constructor.
	 *
	 * @param string $field           The clause's configured field path.
	 * @param string $operator        The clause's configured operator string, exactly as stored (may be invalid).
	 * @param mixed  $expected_value  The clause's configured comparison value.
	 * @param mixed  $actual_value    The event's own value at that field, or null if absent.
	 * @param bool   $field_present   Whether the field was present on the event (EventEnvelope::value_at() returned non-null).
	 * @param bool   $matched         Whether this clause matched.
	 * @param bool   $field_valid     Whether the field is a member of the event type's own allowed-fields list.
	 * @param bool   $operator_valid  Whether the operator string is a recognized ConditionOperator.
	 */
	public function __construct(
		private readonly string $field,
		private readonly string $operator,
		private readonly mixed $expected_value,
		private readonly mixed $actual_value,
		private readonly bool $field_present,
		private readonly bool $matched,
		private readonly bool $field_valid,
		private readonly bool $operator_valid
	) {}

	/**
	 * The clause's configured field path.
	 *
	 * @return string
	 */
	public function field(): string {
		return $this->field;
	}

	/**
	 * The clause's configured operator string, exactly as stored.
	 *
	 * @return string
	 */
	public function operator(): string {
		return $this->operator;
	}

	/**
	 * The clause's configured comparison value.
	 *
	 * @return mixed
	 */
	public function expected_value(): mixed {
		return $this->expected_value;
	}

	/**
	 * The event's own value at this field, or null if absent.
	 *
	 * @return mixed
	 */
	public function actual_value(): mixed {
		return $this->actual_value;
	}

	/**
	 * Whether the field was present on the event.
	 *
	 * @return bool
	 */
	public function field_present(): bool {
		return $this->field_present;
	}

	/**
	 * Whether this clause matched.
	 *
	 * @return bool
	 */
	public function matched(): bool {
		return $this->matched;
	}

	/**
	 * Whether the field is a member of the event type's own allowed-fields
	 * list.
	 *
	 * @return bool
	 */
	public function field_valid(): bool {
		return $this->field_valid;
	}

	/**
	 * Whether the operator string is a recognized ConditionOperator.
	 *
	 * @return bool
	 */
	public function operator_valid(): bool {
		return $this->operator_valid;
	}
}
