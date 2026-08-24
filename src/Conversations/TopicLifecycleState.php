<?php
/**
 * Telegram topic lifecycle state constants.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Conversations;

/**
 * Independent of ConversationStatus: owns Telegram-topic health and the
 * remote-delete workflow (M07.1, docs/adr/0031).
 */
final class TopicLifecycleState {

	public const NONE          = 'none';
	public const ACTIVE        = 'active';
	public const UNAVAILABLE   = 'unavailable';
	public const DELETE_PENDING = 'delete_pending';
	public const DELETE_FAILED = 'delete_failed';

	/**
	 * Every recognized topic-lifecycle state.
	 *
	 * @return array<int, string>
	 */
	public static function all(): array {
		return array(
			self::NONE,
			self::ACTIVE,
			self::UNAVAILABLE,
			self::DELETE_PENDING,
			self::DELETE_FAILED,
		);
	}
}
