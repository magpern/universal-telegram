<?php
/**
 * SC-M03 final-cutover run value object.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Migration;

/**
 * One row of `cutover_runs` (docs/adr/0042 §1). Carries only ids, the fixed
 * state vocabulary, a count, and timestamps — never a binding UUID list or
 * any candidate content.
 */
final class CutoverRun {

	/**
	 * Constructor.
	 *
	 * @param int          $id           Primary key.
	 * @param string       $run_uuid     The cutover_run_id every audit row for this run carries.
	 * @param CutoverState $state        Current state.
	 * @param int          $cohort_count Number of candidates in this run's cohort.
	 * @param string       $created_at   Creation timestamp.
	 * @param string       $updated_at   Last-updated timestamp.
	 */
	public function __construct(
		private readonly int $id,
		private readonly string $run_uuid,
		private readonly CutoverState $state,
		private readonly int $cohort_count,
		private readonly string $created_at,
		private readonly string $updated_at
	) {}

	/**
	 * Primary key.
	 *
	 * @return int
	 */
	public function id(): int {
		return $this->id;
	}

	/**
	 * The `cutover_run_id` every audit row for this run carries.
	 *
	 * @return string
	 */
	public function run_uuid(): string {
		return $this->run_uuid;
	}

	/**
	 * Current state.
	 *
	 * @return CutoverState
	 */
	public function state(): CutoverState {
		return $this->state;
	}

	/**
	 * Number of candidates in this run's cohort.
	 *
	 * @return int
	 */
	public function cohort_count(): int {
		return $this->cohort_count;
	}

	/**
	 * Creation timestamp.
	 *
	 * @return string
	 */
	public function created_at(): string {
		return $this->created_at;
	}

	/**
	 * Last-updated timestamp.
	 *
	 * @return string
	 */
	public function updated_at(): string {
		return $this->updated_at;
	}
}
