<?php
/**
 * One rule's own notification-test outcome.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Administration\Automations;

/**
 * Immutable result of NotificationTester::test_rule()/test_event() for one
 * rule (M08.2 plan §2). `has_unrepresentable_legacy_conditions` is an
 * independent flag, never a substitute outcome — a legacy rule is still
 * genuinely evaluated against its real stored conditions and can land on
 * any `outcome`; the page renders the compatibility notice alongside the
 * outcome, not instead of it.
 */
final class NotificationTestResult {

	/**
	 * Constructor.
	 *
	 * @param int                       $rule_id                              The tested rule's primary key.
	 * @param string                    $rule_name                            The tested rule's admin-facing name.
	 * @param NotificationTestOutcome   $outcome                              This rule's own outcome.
	 * @param array<int, string>        $failing_reasons                      Plain-language failing-condition sentences; non-empty only for NOT_MATCHED.
	 * @param string|null               $rendered_preview                     The rendered example notification preview; non-null only for WOULD_SEND.
	 * @param bool                      $has_unrepresentable_legacy_conditions Whether this rule's stored conditions include a clause the friendly builder cannot represent.
	 */
	public function __construct(
		private readonly int $rule_id,
		private readonly string $rule_name,
		private readonly NotificationTestOutcome $outcome,
		private readonly array $failing_reasons,
		private readonly ?string $rendered_preview,
		private readonly bool $has_unrepresentable_legacy_conditions
	) {}

	/**
	 * The tested rule's primary key.
	 *
	 * @return int
	 */
	public function rule_id(): int {
		return $this->rule_id;
	}

	/**
	 * The tested rule's admin-facing name.
	 *
	 * @return string
	 */
	public function rule_name(): string {
		return $this->rule_name;
	}

	/**
	 * This rule's own outcome.
	 *
	 * @return NotificationTestOutcome
	 */
	public function outcome(): NotificationTestOutcome {
		return $this->outcome;
	}

	/**
	 * Plain-language failing-condition sentences; non-empty only for
	 * NOT_MATCHED.
	 *
	 * @return array<int, string>
	 */
	public function failing_reasons(): array {
		return $this->failing_reasons;
	}

	/**
	 * The rendered example notification preview; non-null only for
	 * WOULD_SEND.
	 *
	 * @return string|null
	 */
	public function rendered_preview(): ?string {
		return $this->rendered_preview;
	}

	/**
	 * Whether this rule's stored conditions include a clause the friendly
	 * builder cannot represent.
	 *
	 * @return bool
	 */
	public function has_unrepresentable_legacy_conditions(): bool {
		return $this->has_unrepresentable_legacy_conditions;
	}
}
