<?php
/**
 * Conversation message value object.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Conversations;

/**
 * Immutable read model of one row of
 * universal_telegram_conversation_messages. body_ciphertext is the raw
 * stored envelope; only MessageRepository::decrypt() ever turns it into
 * plaintext, and only for the authenticated caller of a specific request
 * (docs/adr/0021, M05 plan §4, §9).
 */
final class ConversationMessage {

	/**
	 * Constructor.
	 *
	 * @param int         $id                     Primary key.
	 * @param int         $conversation_id        The owning conversation.
	 * @param string      $message_uuid           Public, opaque identifier; also the encryption AAD context suffix.
	 * @param string      $direction              'visitor' or 'operator'.
	 * @param string|null $body_ciphertext        The stored CredentialVault envelope, or null once nulled by retention.
	 * @param string|null $outbound_message_uuid  Correlates to universal_telegram_outbound_messages, for visitor-direction rows once queued.
	 * @param int|null    $telegram_message_id    The Telegram message id, once delivered or received.
	 * @param string      $delivery_state         stored|sent|failed.
	 * @param string      $created_at             Creation timestamp.
	 * @param string|null $idempotency_key        Client-supplied per-message idempotency key, or null (M06 plan §0, ADR-0021 amendment).
	 */
	public function __construct(
		private readonly int $id,
		private readonly int $conversation_id,
		private readonly string $message_uuid,
		private readonly string $direction,
		private readonly ?string $body_ciphertext,
		private readonly ?string $outbound_message_uuid,
		private readonly ?int $telegram_message_id,
		private readonly string $delivery_state,
		private readonly string $created_at,
		private readonly ?string $idempotency_key = null
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
	 * The owning conversation.
	 *
	 * @return int
	 */
	public function conversation_id(): int {
		return $this->conversation_id;
	}

	/**
	 * Public, opaque identifier; also the encryption AAD context suffix.
	 *
	 * @return string
	 */
	public function message_uuid(): string {
		return $this->message_uuid;
	}

	/**
	 * 'visitor' or 'operator'.
	 *
	 * @return string
	 */
	public function direction(): string {
		return $this->direction;
	}

	/**
	 * The stored CredentialVault envelope, or null once nulled by retention.
	 * Never serialize this value into a REST response.
	 *
	 * @return string|null
	 */
	public function body_ciphertext(): ?string {
		return $this->body_ciphertext;
	}

	/**
	 * Correlates to universal_telegram_outbound_messages, for
	 * visitor-direction rows once queued.
	 *
	 * @return string|null
	 */
	public function outbound_message_uuid(): ?string {
		return $this->outbound_message_uuid;
	}

	/**
	 * The Telegram message id, once delivered or received.
	 *
	 * @return int|null
	 */
	public function telegram_message_id(): ?int {
		return $this->telegram_message_id;
	}

	/**
	 * One of stored|sent|failed.
	 *
	 * @return string
	 */
	public function delivery_state(): string {
		return $this->delivery_state;
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
	 * Client-supplied per-message idempotency key, or null. Never included
	 * in a poll response (M06 plan §0, ADR-0021 amendment).
	 *
	 * @return string|null
	 */
	public function idempotency_key(): ?string {
		return $this->idempotency_key;
	}
}
