<?php
/**
 * Condition clause operator.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Automations;

/**
 * The fixed, closed set of operators a condition clause may use (M02 plan
 * §7.2). No arbitrary expression or scripting language exists in this
 * grammar.
 */
enum ConditionOperator: string {
	case EQUALS = 'equals';
	case NOT_EQUALS = 'not_equals';
	case CONTAINS = 'contains';
	case GREATER_THAN = 'greater_than';
	case LESS_THAN = 'less_than';
	case IN = 'in';

	/**
	 * Evaluates this operator against an actual and an expected value.
	 * Never throws: an incomparable pairing simply evaluates false.
	 *
	 * @param mixed $actual   The event's own field value.
	 * @param mixed $expected The rule clause's configured value.
	 *
	 * @return bool
	 */
	public function matches( $actual, $expected ): bool {
		return match ( $this ) {
			self::EQUALS => $this->loose_equals( $actual, $expected ),
			self::NOT_EQUALS => ! $this->loose_equals( $actual, $expected ),
			self::CONTAINS => is_string( $actual ) && is_string( $expected ) && '' !== $expected && str_contains( $actual, $expected ),
			self::GREATER_THAN => is_numeric( $actual ) && is_numeric( $expected ) && (float) $actual > (float) $expected,
			self::LESS_THAN => is_numeric( $actual ) && is_numeric( $expected ) && (float) $actual < (float) $expected,
			self::IN => is_array( $expected ) && in_array( $actual, $expected, false ),
		};
	}

	/**
	 * Loose, type-juggling-free equality: identical scalar comparison,
	 * coercing both sides to string first so "1" and 1 are considered
	 * equal (JSON-decoded values are frequently strings).
	 *
	 * @param mixed $actual   The event's own field value.
	 * @param mixed $expected The rule clause's configured value.
	 *
	 * @return bool
	 */
	private function loose_equals( $actual, $expected ): bool {
		if ( is_array( $actual ) || is_array( $expected ) ) {
			return false;
		}

		return (string) $actual === (string) $expected;
	}
}
