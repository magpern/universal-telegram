<?php
/**
 * The full, non-short-circuited result of evaluating one rule's conditions.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Automations;

/**
 * Returned by RuleEvaluator::evaluate_conditions() (M08.2 plan §1). Every
 * clause is evaluated and recorded, never short-circuited, so a caller
 * explaining an any-mode "none matched" result can show every clause's own
 * reason rather than only the first. Production's own rejection_reason()
 * derives its fixed reason-code string from this same trace — there is
 * exactly one condition-evaluation algorithm, not two.
 */
final class RuleMatchTrace {

	/**
	 * Constructor.
	 *
	 * @param bool                              $matched         Whether the rule's conditions matched overall.
	 * @param string                            $match_mode      'all' or 'any' (ADR-0032).
	 * @param array<int, ConditionClauseResult> $clause_results Every clause's own result, in configured order.
	 */
	public function __construct(
		private readonly bool $matched,
		private readonly string $match_mode,
		private readonly array $clause_results
	) {}

	/**
	 * Whether the rule's conditions matched overall.
	 *
	 * @return bool
	 */
	public function matched(): bool {
		return $this->matched;
	}

	/**
	 * 'all' or 'any' (ADR-0032).
	 *
	 * @return string
	 */
	public function match_mode(): string {
		return $this->match_mode;
	}

	/**
	 * Every clause's own result, in configured order.
	 *
	 * @return array<int, ConditionClauseResult>
	 */
	public function clause_results(): array {
		return $this->clause_results;
	}

	/**
	 * Whether any clause referenced a field or operator this evaluation
	 * could not validate — the condition-list-level counterpart of a
	 * single clause's own field_valid()/operator_valid() flags.
	 *
	 * @return bool
	 */
	public function has_invalid_clause(): bool {
		foreach ( $this->clause_results as $clause_result ) {
			if ( ! $clause_result->field_valid() || ! $clause_result->operator_valid() ) {
				return true;
			}
		}

		return false;
	}
}
