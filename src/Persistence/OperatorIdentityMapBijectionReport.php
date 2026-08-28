<?php
/**
 * Result of the operator-identity-map bijection check (ADR-0044 §4).
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Persistence;

/**
 * Immutable outcome of {@see OperatorIdentityMapMigration::verify_bijection()}.
 *
 * `holds()` is true only when there is no conflict and no missing pair.
 * Unreachable extra map rows never fail the check; they are reported so a
 * caller (step 37 log, purge command output) can surface them.
 */
final class OperatorIdentityMapBijectionReport {

	/**
	 * Human-readable conflict / missing-pair descriptions.
	 *
	 * @var array<int, string>
	 */
	private array $mismatches;

	/**
	 * Map rows unreachable through either source key: {id, wp_user_id, telegram_user_id}.
	 *
	 * @var array<int, array{id:int, wp_user_id:int, telegram_user_id:int}>
	 */
	private array $unreachable_extras;

	/**
	 * Constructor.
	 *
	 * @param array<int, string>                                                    $mismatches         Conflict / missing-pair descriptions.
	 * @param array<int, array{id:int, wp_user_id:int, telegram_user_id:int}>        $unreachable_extras Extra map rows.
	 */
	public function __construct( array $mismatches, array $unreachable_extras ) {
		$this->mismatches         = array_values( $mismatches );
		$this->unreachable_extras = array_values( $unreachable_extras );
	}

	/**
	 * Whether the bijection holds (no conflict, no missing pair).
	 *
	 * @return bool
	 */
	public function holds(): bool {
		return array() === $this->mismatches;
	}

	/**
	 * Conflict and missing-pair descriptions.
	 *
	 * @return array<int, string>
	 */
	public function mismatches(): array {
		return $this->mismatches;
	}

	/**
	 * Map rows unreachable through either source key.
	 *
	 * @return array<int, array{id:int, wp_user_id:int, telegram_user_id:int}>
	 */
	public function unreachable_extras(): array {
		return $this->unreachable_extras;
	}
}
