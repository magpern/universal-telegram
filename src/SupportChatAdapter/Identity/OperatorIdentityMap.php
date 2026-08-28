<?php
/**
 * Operator identity mapping value object.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\SupportChatAdapter\Identity;

/**
 * Immutable read model of one row of
 * universal_telegram_operator_identity_map: the manually maintained
 * WordPress-user <-> Telegram numeric-user-id mapping that is the plugin's
 * inbound Telegram operator-authorization gate (M07, docs/adr/0026).
 * telegram_user_id and telegram_username are both SENSITIVE personal data
 * — callers must never render, URL-expose, search-filter, or audit-log
 * either in raw form outside the MANAGE-gated OperatorIdentityPage itself.
 */
final class OperatorIdentityMap {

	/**
	 * Constructor.
	 *
	 * @param int         $id                Primary key.
	 * @param int         $wp_user_id        The mapped WordPress user.
	 * @param int         $telegram_user_id  The mapped Telegram numeric sender id. SENSITIVE.
	 * @param string|null $telegram_username The Telegram username at mapping time, if known. SENSITIVE.
	 * @param string      $created_at        Creation timestamp.
	 * @param int         $created_by        The WordPress user who created this mapping.
	 */
	public function __construct(
		private readonly int $id,
		private readonly int $wp_user_id,
		private readonly int $telegram_user_id,
		private readonly ?string $telegram_username,
		private readonly string $created_at,
		private readonly int $created_by
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
	 * The mapped WordPress user.
	 *
	 * @return int
	 */
	public function wp_user_id(): int {
		return $this->wp_user_id;
	}

	/**
	 * The mapped Telegram numeric sender id. SENSITIVE — never render,
	 * URL-expose, search-filter, or audit-log this value.
	 *
	 * @return int
	 */
	public function telegram_user_id(): int {
		return $this->telegram_user_id;
	}

	/**
	 * The Telegram username at mapping time, if known. SENSITIVE — display
	 * only on the MANAGE-gated OperatorIdentityPage itself.
	 *
	 * @return string|null
	 */
	public function telegram_username(): ?string {
		return $this->telegram_username;
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
	 * The WordPress user who created this mapping.
	 *
	 * @return int
	 */
	public function created_by(): int {
		return $this->created_by;
	}
}
