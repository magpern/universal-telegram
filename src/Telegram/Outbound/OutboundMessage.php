<?php
/**
 * Outbound message value object.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Telegram\Outbound;

/**
 * Immutable read model of one row of universal_telegram_outbound_messages —
 * the only place message content is ever durably stored, encrypted, outside
 * any queue payload (docs/adr/0012). Never carries plaintext body; only
 * OutboundMessageRepository decrypts it, purpose-bound, at send time.
 */
final class OutboundMessage {

	/**
	 * Constructor.
	 *
	 * @param int                   $id                            Primary key.
	 * @param string                $message_uuid                  The only identifier ever placed in a JobEnvelope.
	 * @param int                   $bot_id                        The owning bot's primary key.
	 * @param int                   $destination_id                The target destination's primary key.
	 * @param string|null           $body_ciphertext               CredentialVault-encrypted message text, null once purged.
	 * @param string|null           $parse_mode                    Telegram's own parse_mode parameter.
	 * @param OutboundMessageStatus $status                        The lifecycle status.
	 * @param int                   $attempt_count                 How many send attempts have been made.
	 * @param string|null           $last_failure_code              A fixed stable code, never raw API text.
	 * @param bool                  $possible_duplicate_delivery    Set once, never cleared.
	 * @param string|null           $dead_lettered_at                When this message was dead-lettered.
	 * @param int|null              $telegram_message_id            Returned on success.
	 * @param string                $created_at                     Creation timestamp.
	 * @param string                $updated_at                     Last-modified timestamp.
	 * @param string|null           $sent_at                        When this message was confirmed sent.
	 * @param string|null           $claim_expires_at                When the currently held sending claim/lease expires, or null if unclaimed (M06.2 corrective plan v2, ADR-0023 amendment).
	 * @param string                $delivery_class                  Fixed transport priority class (docs/adr/0045); `standard` or `interactive_chat`, never content.
	 */
	public function __construct(
		private readonly int $id,
		private readonly string $message_uuid,
		private readonly int $bot_id,
		private readonly int $destination_id,
		private readonly ?string $body_ciphertext,
		private readonly ?string $parse_mode,
		private readonly OutboundMessageStatus $status,
		private readonly int $attempt_count,
		private readonly ?string $last_failure_code,
		private readonly bool $possible_duplicate_delivery,
		private readonly ?string $dead_lettered_at,
		private readonly ?int $telegram_message_id,
		private readonly string $created_at,
		private readonly string $updated_at,
		private readonly ?string $sent_at,
		private readonly ?string $claim_expires_at = null,
		private readonly string $delivery_class = 'standard'
	) {}

	/**
	 * The fixed transport priority class (docs/adr/0045). `standard` for
	 * every ordinary send; `interactive_chat` only for a Support Chat
	 * website-chat message. Never message content.
	 *
	 * @return string
	 */
	public function delivery_class(): string {
		return $this->delivery_class;
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
	 * The only identifier ever placed in a JobEnvelope.
	 *
	 * @return string
	 */
	public function message_uuid(): string {
		return $this->message_uuid;
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
	 * The target destination's primary key.
	 *
	 * @return int
	 */
	public function destination_id(): int {
		return $this->destination_id;
	}

	/**
	 * CredentialVault-encrypted message text, null once purged.
	 *
	 * @return string|null
	 */
	public function body_ciphertext(): ?string {
		return $this->body_ciphertext;
	}

	/**
	 * Telegram's own parse_mode parameter.
	 *
	 * @return string|null
	 */
	public function parse_mode(): ?string {
		return $this->parse_mode;
	}

	/**
	 * The lifecycle status.
	 *
	 * @return OutboundMessageStatus
	 */
	public function status(): OutboundMessageStatus {
		return $this->status;
	}

	/**
	 * How many send attempts have been made.
	 *
	 * @return int
	 */
	public function attempt_count(): int {
		return $this->attempt_count;
	}

	/**
	 * A fixed stable code, never raw API text.
	 *
	 * @return string|null
	 */
	public function last_failure_code(): ?string {
		return $this->last_failure_code;
	}

	/**
	 * Set once, never cleared, whenever a send attempt fails at the
	 * network-transport level.
	 *
	 * @return bool
	 */
	public function possible_duplicate_delivery(): bool {
		return $this->possible_duplicate_delivery;
	}

	/**
	 * When this message was dead-lettered.
	 *
	 * @return string|null
	 */
	public function dead_lettered_at(): ?string {
		return $this->dead_lettered_at;
	}

	/**
	 * Returned on success.
	 *
	 * @return int|null
	 */
	public function telegram_message_id(): ?int {
		return $this->telegram_message_id;
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
	 * Last-modified timestamp.
	 *
	 * @return string
	 */
	public function updated_at(): string {
		return $this->updated_at;
	}

	/**
	 * When this message was confirmed sent.
	 *
	 * @return string|null
	 */
	public function sent_at(): ?string {
		return $this->sent_at;
	}

	/**
	 * When the currently held sending claim/lease expires, or null if
	 * unclaimed. Only meaningful while status() is SENDING (M06.2
	 * corrective plan v2, ADR-0023 amendment).
	 *
	 * @return string|null
	 */
	public function claim_expires_at(): ?string {
		return $this->claim_expires_at;
	}
}
