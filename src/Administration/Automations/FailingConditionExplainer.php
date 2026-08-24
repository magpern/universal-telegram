<?php
/**
 * Plain-language failing-condition explanations.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Administration\Automations;

use UniversalTelegram\Automations\ConditionClauseResult;
use UniversalTelegram\Automations\RuleMatchTrace;

/**
 * Formats a RuleEvaluator::evaluate_conditions() trace (M08.2 plan §1)
 * into administrator-readable sentences — pure formatting over an
 * already-computed trace, never a second condition-evaluation
 * implementation. For match_mode='all', every non-matching, validly
 * catalogued clause gets its own sentence (each is independently
 * necessary). For match_mode='any', the same holds — since the trace is
 * only ever non-matched here because every clause independently failed to
 * match, so every clause's own sentence is equally informative; there is
 * no single "first" reason more useful than the others. A clause with an
 * invalid field or operator never gets a sentence of its own — that
 * condition is not safely explainable in friendly terms, so the caller is
 * expected to show the separate legacy-compatibility notice for it
 * instead (NotificationTestResult::has_unrepresentable_legacy_conditions).
 */
final class FailingConditionExplainer {

	/**
	 * Every failing-clause sentence for a non-matched trace. Returns an
	 * empty array if the trace matched, or if every non-matching clause was
	 * an invalid field/operator (those are covered by the compatibility
	 * notice, not prose).
	 *
	 * @param RuleMatchTrace $trace The rule's own evaluated trace.
	 *
	 * @return array<int, string>
	 */
	public static function explain( RuleMatchTrace $trace ): array {
		if ( $trace->matched() ) {
			return array();
		}

		$sentences = array();

		foreach ( $trace->clause_results() as $clause_result ) {
			if ( ! $clause_result->field_valid() || ! $clause_result->operator_valid() ) {
				continue;
			}

			if ( $clause_result->matched() ) {
				continue;
			}

			$sentences[] = self::explain_clause( $clause_result );
		}

		return $sentences;
	}

	/**
	 * One clause's own sentence.
	 *
	 * @param ConditionClauseResult $clause_result The clause's own evaluated result.
	 *
	 * @return string
	 */
	private static function explain_clause( ConditionClauseResult $clause_result ): string {
		$field_label = FieldTypeCatalog::label( $clause_result->field() ) ?? EventCatalogLabels::field_label( $clause_result->field() );

		if ( ! $clause_result->field_present() ) {
			return sprintf(
				'This notification checks %s, but this example has no value for it, so this condition cannot match.',
				$field_label
			);
		}

		$operator_label    = self::operator_label( $clause_result->operator() );
		$actual_display    = self::format_value( $clause_result->field(), $clause_result->actual_value() );
		$expected_display  = self::format_value( $clause_result->field(), $clause_result->expected_value() );

		return sprintf(
			'%1$s is currently "%2$s", which does not %3$s "%4$s".',
			$field_label,
			$actual_display,
			$operator_label,
			$expected_display
		);
	}

	/**
	 * The friendly operator label, falling back to the raw operator string
	 * if unrecognized (should not happen for a clause already confirmed
	 * operator_valid()).
	 *
	 * @param string $operator The stored operator string.
	 *
	 * @return string
	 */
	private static function operator_label( string $operator ): string {
		return ConditionRowRenderer::operator_labels()[ $operator ] ?? $operator;
	}

	/**
	 * The friendly display value for one field's value, translating a
	 * choice/boolean field's stored raw value into its own catalogued
	 * label rather than showing the raw stored string.
	 *
	 * @param string $field The field path.
	 * @param mixed  $value The raw stored or actual value.
	 *
	 * @return string
	 */
	private static function format_value( string $field, mixed $value ): string {
		if ( null === $value ) {
			return '';
		}

		$type = FieldTypeCatalog::type( $field );

		if ( FieldTypeCatalog::TYPE_CHOICE === $type ) {
			return FieldTypeCatalog::choice_options( $field )[ (string) $value ] ?? (string) $value;
		}

		if ( FieldTypeCatalog::TYPE_BOOLEAN === $type ) {
			return ConditionRowRenderer::boolean_value_labels()[ (string) $value ] ?? (string) $value;
		}

		return (string) $value;
	}
}
