<?php
/**
 * Notification rule value object.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Automations;

/**
 * Immutable read model of one row of universal_telegram_notification_rules.
 * conditions() is a flat, non-nested clause array; match_mode() determines
 * whether every clause must evaluate true ('all', the original M02 §7.2
 * behavior and every legacy rule's default) or at least one clause with a
 * present field must ('any' — M08.1, ADR-0032). Never nested, never OR
 * within a single clause.
 */
final class NotificationRule {

	/**
	 * Constructor.
	 *
	 * @param int                              $id                  Primary key.
	 * @param string                           $name                Admin-facing name.
	 * @param string                           $event_type          The triggering event type.
	 * @param int                              $schema_version_min  The minimum schema version this rule applies to.
	 * @param array<int, array<string, mixed>> $conditions Flat clause array.
	 * @param string                           $match_mode          'all' or 'any' (ADR-0032).
	 * @param int                              $bot_id              The Telegram bot to send through.
	 * @param int                              $destination_id      The Telegram destination to send to.
	 * @param string                           $template            The message template.
	 * @param bool                             $enabled             Whether this rule is currently evaluated.
	 * @param int                              $priority            Deterministic evaluation ordering (ascending).
	 * @param int                              $cooldown_seconds    Minimum seconds between successful dispatches.
	 * @param string                           $created_at          Creation timestamp.
	 * @param string                           $updated_at          Last-update timestamp.
	 */
	public function __construct(
		private readonly int $id,
		private readonly string $name,
		private readonly string $event_type,
		private readonly int $schema_version_min,
		private readonly array $conditions,
		private readonly string $match_mode,
		private readonly int $bot_id,
		private readonly int $destination_id,
		private readonly string $template,
		private readonly bool $enabled,
		private readonly int $priority,
		private readonly int $cooldown_seconds,
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
	 * Admin-facing name.
	 *
	 * @return string
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * The triggering event type.
	 *
	 * @return string
	 */
	public function event_type(): string {
		return $this->event_type;
	}

	/**
	 * The minimum schema version this rule applies to.
	 *
	 * @return int
	 */
	public function schema_version_min(): int {
		return $this->schema_version_min;
	}

	/**
	 * Flat, AND-only clause array.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function conditions(): array {
		return $this->conditions;
	}

	/**
	 * Whether every clause must match ('all') or at least one present-field
	 * clause must match ('any') for this rule to match (ADR-0032).
	 *
	 * @return string
	 */
	public function match_mode(): string {
		return $this->match_mode;
	}

	/**
	 * The Telegram bot to send through.
	 *
	 * @return int
	 */
	public function bot_id(): int {
		return $this->bot_id;
	}

	/**
	 * The Telegram destination to send to.
	 *
	 * @return int
	 */
	public function destination_id(): int {
		return $this->destination_id;
	}

	/**
	 * The message template.
	 *
	 * @return string
	 */
	public function template(): string {
		return $this->template;
	}

	/**
	 * Whether this rule is currently evaluated.
	 *
	 * @return bool
	 */
	public function enabled(): bool {
		return $this->enabled;
	}

	/**
	 * Deterministic evaluation ordering (ascending).
	 *
	 * @return int
	 */
	public function priority(): int {
		return $this->priority;
	}

	/**
	 * Minimum seconds between successful dispatches.
	 *
	 * @return int
	 */
	public function cooldown_seconds(): int {
		return $this->cooldown_seconds;
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
	 * Last-update timestamp.
	 *
	 * @return string
	 */
	public function updated_at(): string {
		return $this->updated_at;
	}
}
