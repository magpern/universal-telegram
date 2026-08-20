<?php
/**
 * Per-request schema-availability state.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Persistence;

/**
 * Constructed once per request by the composition root and never
 * persisted: a degraded state is recomputed fresh on the next request by
 * re-attempting the migration, so recovery from a transient cause is
 * automatic.
 */
final class SchemaHealth {

	/**
	 * Whether the schema is currently usable for this request.
	 *
	 * @var bool
	 */
	private bool $available = true;

	/**
	 * The stable failure code, if unavailable.
	 *
	 * @var MigrationFailureCode|null
	 */
	private ?MigrationFailureCode $failure_code = null;

	/**
	 * Marks the schema unavailable for the remainder of this request.
	 *
	 * @param MigrationFailureCode $failure_code The stable failure code.
	 */
	public function mark_unavailable( MigrationFailureCode $failure_code ): void {
		$this->available    = false;
		$this->failure_code = $failure_code;
	}

	/**
	 * Whether the schema is currently usable for this request.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return $this->available;
	}

	/**
	 * The stable failure code, if unavailable.
	 *
	 * @return MigrationFailureCode|null
	 */
	public function failure_code(): ?MigrationFailureCode {
		return $this->failure_code;
	}
}
