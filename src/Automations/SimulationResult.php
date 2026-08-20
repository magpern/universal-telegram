<?php
/**
 * Rule simulation result.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Automations;

/**
 * The outcome of one Automations\RuleSimulator::simulate() call: every
 * evaluated rule's outcome, in the same deterministic order real
 * evaluation uses (M02 plan §9.2).
 */
final class SimulationResult {

	/**
	 * Constructor.
	 *
	 * @param array<int, array{rule_id: int, rule_name: string, outcome: string, reason_code: string|null}> $entries Per-rule outcomes, in evaluation order.
	 * @param string|null $error_code A fixed error code if the sample data itself could not be evaluated (e.g. an unregistered event type).
	 */
	public function __construct(
		private readonly array $entries,
		private readonly ?string $error_code = null
	) {}

	/**
	 * Per-rule outcomes, in evaluation order.
	 *
	 * @return array<int, array{rule_id: int, rule_name: string, outcome: string, reason_code: string|null}>
	 */
	public function entries(): array {
		return $this->entries;
	}

	/**
	 * A fixed error code if the sample data itself could not be evaluated.
	 *
	 * @return string|null
	 */
	public function error_code(): ?string {
		return $this->error_code;
	}
}
