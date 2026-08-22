<?php
/**
 * Operator availability value object.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Conversations;

/**
 * Immutable read model of one row of
 * universal_telegram_operator_availability: an operator's three-state
 * presence (M07, docs/adr/0026). INTERNAL, not SENSITIVE — the state
 * itself identifies no one beyond the already-authenticated operator
 * viewing the Hub.
 */
final class OperatorAvailability {

	public const AVAILABLE = 'available';
	public const BUSY      = 'busy';
	public const OFFLINE   = 'offline';

	/**
	 * Constructor.
	 *
	 * @param int    $id               Primary key.
	 * @param int    $operator_user_id The operator this state belongs to.
	 * @param string $state            One of available|busy|offline.
	 * @param string $updated_at       Last-update timestamp.
	 * @param int    $updated_by       The WordPress user who last set this state (may differ from operator_user_id for a MANAGE override).
	 */
	public function __construct(
		private readonly int $id,
		private readonly int $operator_user_id,
		private readonly string $state,
		private readonly string $updated_at,
		private readonly int $updated_by
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
	 * The operator this state belongs to.
	 *
	 * @return int
	 */
	public function operator_user_id(): int {
		return $this->operator_user_id;
	}

	/**
	 * One of available|busy|offline.
	 *
	 * @return string
	 */
	public function state(): string {
		return $this->state;
	}

	/**
	 * Last-update timestamp.
	 *
	 * @return string
	 */
	public function updated_at(): string {
		return $this->updated_at;
	}

	/**
	 * The WordPress user who last set this state (may differ from
	 * operator_user_id for a MANAGE administrator override).
	 *
	 * @return int
	 */
	public function updated_by(): int {
		return $this->updated_by;
	}
}
