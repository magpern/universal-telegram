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
	case EQUALS       = 'equals';
	case NOT_EQUALS   = 'not_equals';
	case CONTAINS     = 'contains';
	case NOT_CONTAINS = 'not_contains';
	case GREATER_THAN = 'greater_than';
	case LESS_THAN    = 'less_than';
	case AT_LEAST     = 'at_least';
	case AT_MOST      = 'at_most';
	case IN           = 'in';

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
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- $this is fully valid inside a backed enum's own instance method under PHP 8.1+ (each case is its own singleton instance); this sniff version does not recognize enum methods as object context, a known false positive.
		return match ( $this ) {
			self::EQUALS => self::loose_equals_static( $actual, $expected ),
			self::NOT_EQUALS => ! self::loose_equals_static( $actual, $expected ),
			self::CONTAINS => is_string( $actual ) && is_string( $expected ) && '' !== $expected && str_contains( $actual, $expected ),
			self::NOT_CONTAINS => is_string( $actual ) && is_string( $expected ) && '' !== $expected && ! str_contains( $actual, $expected ),
			self::GREATER_THAN => is_numeric( $actual ) && is_numeric( $expected ) && (float) $actual > (float) $expected,
			self::LESS_THAN => is_numeric( $actual ) && is_numeric( $expected ) && (float) $actual < (float) $expected,
			self::AT_LEAST => is_numeric( $actual ) && is_numeric( $expected ) && (float) $actual >= (float) $expected,
			self::AT_MOST => is_numeric( $actual ) && is_numeric( $expected ) && (float) $actual <= (float) $expected,
			self::IN => is_array( $expected ) && in_array( $actual, $expected, true ),
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
	private static function loose_equals_static( $actual, $expected ): bool {
		if ( is_array( $actual ) || is_array( $expected ) ) {
			return false;
		}

		return (string) $actual === (string) $expected;
	}
}
