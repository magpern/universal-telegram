<?php
/**
 * Conversation value object.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Conversations;

/**
 * Immutable read model of one row of universal_telegram_conversations.
 * secret_hash is intentionally not exposed by any accessor other than
 * secret_hash() itself, which exists only for password_verify() call sites
 * inside this boundary — it is never serialized into a REST response
 * (docs/adr/0021, M05 plan §3–§4).
 */
final class Conversation {

	/**
	 * Constructor.
	 *
	 * @param int         $id                     Primary key.
	 * @param string      $conversation_uuid      Public, opaque identifier.
	 * @param string|null $secret_hash            password_hash() of the bearer secret, or null once revoked.
	 * @param int         $bot_id                 The Telegram bot this conversation belongs to.
	 * @param int|null    $destination_id         The conversation's own destination row, once its topic exists.
	 * @param string|null $chat_profile           The configured profile requested at start, if any.
	 * @param string      $status                 One of the ConversationStatus constants.
	 * @param int|null    $assigned_operator_id   Reserved for M7; never set by M05.
	 * @param string      $topic_creation_state   none|pending|created|failed.
	 * @param int|null    $telegram_topic_id      The Telegram forum topic id, once created.
	 * @param string      $ai_participation_state Reserved for M9+; unused in M05.
	 * @param string      $consent_state          unknown|granted|declined.
	 * @param string|null $session_ref            Opaque visitor session correlation reference.
	 * @param string      $created_at             Creation timestamp.
	 * @param string      $updated_at             Last-update timestamp.
	 * @param string|null $resolved_at            Timestamp of the last `* -> resolved` transition.
	 * @param string|null $expires_at             Reserved retention timestamp.
	 * @param string|null $start_idempotency_key  Client-supplied idempotency key from the start request that created this row, or null (M06 plan §0, ADR-0021 amendment).
	 */
	public function __construct(
		private readonly int $id,
		private readonly string $conversation_uuid,
		private readonly ?string $secret_hash,
		private readonly int $bot_id,
		private readonly ?int $destination_id,
		private readonly ?string $chat_profile,
		private readonly string $status,
		private readonly ?int $assigned_operator_id,
		private readonly string $topic_creation_state,
		private readonly ?int $telegram_topic_id,
		private readonly string $ai_participation_state,
		private readonly string $consent_state,
		private readonly ?string $session_ref,
		private readonly string $created_at,
		private readonly string $updated_at,
		private readonly ?string $resolved_at,
		private readonly ?string $expires_at,
		private readonly ?string $start_idempotency_key = null
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
	 * Public, opaque identifier — the sole database lookup key.
	 *
	 * @return string
	 */
	public function conversation_uuid(): string {
		return $this->conversation_uuid;
	}

	/**
	 * The bearer secret's password_hash(), or null once revoked. Never
	 * serialize this value into a REST response.
	 *
	 * @return string|null
	 */
	public function secret_hash(): ?string {
		return $this->secret_hash;
	}

	/**
	 * The Telegram bot this conversation belongs to.
	 *
	 * @return int
	 */
	public function bot_id(): int {
		return $this->bot_id;
	}

	/**
	 * The conversation's own destination row id, once its topic exists.
	 *
	 * @return int|null
	 */
	public function destination_id(): ?int {
		return $this->destination_id;
	}

	/**
	 * The configured chat profile requested at start, if any.
	 *
	 * @return string|null
	 */
	public function chat_profile(): ?string {
		return $this->chat_profile;
	}

	/**
	 * One of the ConversationStatus constants.
	 *
	 * @return string
	 */
	public function status(): string {
		return $this->status;
	}

	/**
	 * Reserved for M7; never set by M05.
	 *
	 * @return int|null
	 */
	public function assigned_operator_id(): ?int {
		return $this->assigned_operator_id;
	}

	/**
	 * One of none|pending|created|failed.
	 *
	 * @return string
	 */
	public function topic_creation_state(): string {
		return $this->topic_creation_state;
	}

	/**
	 * The Telegram forum topic id, once created.
	 *
	 * @return int|null
	 */
	public function telegram_topic_id(): ?int {
		return $this->telegram_topic_id;
	}

	/**
	 * Reserved for M9+; unused in M05.
	 *
	 * @return string
	 */
	public function ai_participation_state(): string {
		return $this->ai_participation_state;
	}

	/**
	 * One of unknown|granted|declined.
	 *
	 * @return string
	 */
	public function consent_state(): string {
		return $this->consent_state;
	}

	/**
	 * Opaque visitor session correlation reference.
	 *
	 * @return string|null
	 */
	public function session_ref(): ?string {
		return $this->session_ref;
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

	/**
	 * Timestamp of the last `* -> resolved` transition.
	 *
	 * @return string|null
	 */
	public function resolved_at(): ?string {
		return $this->resolved_at;
	}

	/**
	 * Reserved retention timestamp.
	 *
	 * @return string|null
	 */
	public function expires_at(): ?string {
		return $this->expires_at;
	}

	/**
	 * The client-supplied idempotency key from the start request that
	 * created this row, or null. The sole lookup key for a safe start
	 * replay (M06 plan §0, ADR-0021 amendment).
	 *
	 * @return string|null
	 */
	public function start_idempotency_key(): ?string {
		return $this->start_idempotency_key;
	}
}
