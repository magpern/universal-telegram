<?php
/**
 * Destination value object.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Telegram\Configuration;

/**
 * Immutable read model of one row of universal_telegram_destinations.
 * Enforces, at construction, that message_thread_id is set only for
 * kind = 'supergroup' — the one and only destination kind M01 supports
 * forum-topic routing for (plan §6, §8).
 */
final class Destination {

	/**
	 * Constructor.
	 *
	 * @param int             $id                 Primary key.
	 * @param int             $bot_id             The owning bot's primary key.
	 * @param DestinationKind $kind               The Telegram chat kind.
	 * @param string          $chat_id             Telegram's own chat identifier.
	 * @param int|null        $message_thread_id   Forum topic ID; only valid when $kind is SUPERGROUP.
	 * @param string          $label               Admin-facing name.
	 * @param bool            $enabled             Whether sends to this destination are currently permitted.
	 * @param string          $created_at          Creation timestamp.
	 *
	 * @throws InvalidDestinationException If message_thread_id is set on any kind other than SUPERGROUP.
	 */
	public function __construct(
		private readonly int $id,
		private readonly int $bot_id,
		private readonly DestinationKind $kind,
		private readonly string $chat_id,
		private readonly ?int $message_thread_id,
		private readonly string $label,
		private readonly bool $enabled,
		private readonly string $created_at
	) {
		if ( null !== $message_thread_id && DestinationKind::SUPERGROUP !== $kind ) {
			throw new InvalidDestinationException(
				sprintf( 'message_thread_id is only valid for supergroup destinations, not "%s".', $kind->value )
			);
		}
	}

	/**
	 * Primary key.
	 *
	 * @return int
	 */
	public function id(): int {
		return $this->id;
	}

	/**
	 * The owning bot's primary key.
	 *
	 * @return int
	 */
	public function bot_id(): int {
		return $this->bot_id;
	}

	/**
	 * The Telegram chat kind.
	 *
	 * @return DestinationKind
	 */
	public function kind(): DestinationKind {
		return $this->kind;
	}

	/**
	 * Telegram's own chat identifier.
	 *
	 * @return string
	 */
	public function chat_id(): string {
		return $this->chat_id;
	}

	/**
	 * Forum topic ID, only ever set for a supergroup destination.
	 *
	 * @return int|null
	 */
	public function message_thread_id(): ?int {
		return $this->message_thread_id;
	}

	/**
	 * Admin-facing name.
	 *
	 * @return string
	 */
	public function label(): string {
		return $this->label;
	}

	/**
	 * Whether sends to this destination are currently permitted.
	 *
	 * @return bool
	 */
	public function enabled(): bool {
		return $this->enabled;
	}

	/**
	 * Creation timestamp.
	 *
	 * @return string
	 */
	public function created_at(): string {
		return $this->created_at;
	}
}
