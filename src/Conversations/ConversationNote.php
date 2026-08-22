<?php
/**
 * Conversation internal note value object.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Conversations;

/**
 * Immutable read model of one row of
 * universal_telegram_conversation_notes: an encrypted, operator-authored
 * internal note, never exposed to Telegram or visitors (M07,
 * docs/adr/0026). operator_user_id is nullable so authorship can be
 * anonymized on operator account deletion without deleting note content —
 * a null author renders as "— former operator —".
 */
final class ConversationNote {

	/**
	 * Constructor.
	 *
	 * @param int      $id               Primary key.
	 * @param int      $conversation_id  The owning conversation.
	 * @param int|null $operator_user_id The authoring operator, or null once anonymized.
	 * @param string   $body_ciphertext  The stored CredentialVault envelope.
	 * @param string   $created_at       Creation timestamp.
	 */
	public function __construct(
		private readonly int $id,
		private readonly int $conversation_id,
		private readonly ?int $operator_user_id,
		private readonly string $body_ciphertext,
		private readonly string $created_at
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
	 * The authoring operator, or null once anonymized on account deletion.
	 *
	 * @return int|null
	 */
	public function operator_user_id(): ?int {
		return $this->operator_user_id;
	}

	/**
	 * The stored CredentialVault envelope. Never serialize this value into
	 * a REST response.
	 *
	 * @return string
	 */
	public function body_ciphertext(): string {
		return $this->body_ciphertext;
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
