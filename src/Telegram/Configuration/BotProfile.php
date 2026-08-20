<?php
/**
 * Bot profile value object.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Telegram\Configuration;

/**
 * Immutable read model of one row of universal_telegram_bots. Never carries
 * plaintext token or webhook-secret material — only ciphertext envelopes,
 * which BotProfileRepository decrypts on demand for a specific, purpose-bound
 * caller (TelegramApiClient, WebhookSecretVerifier). No admin surface may
 * ever render token_ciphertext() or webhook_secret_ciphertext() (docs/adr/0012 A12).
 */
final class BotProfile {

	/**
	 * Constructor.
	 *
	 * @param int         $id                                 Primary key.
	 * @param string      $bot_uuid                            Opaque, non-secret webhook route identifier.
	 * @param string      $name                                Admin-facing label.
	 * @param string      $token_ciphertext                    CredentialVault-encrypted bot token.
	 * @param string      $webhook_secret_ciphertext           CredentialVault-encrypted active webhook secret.
	 * @param string|null $webhook_secret_pending_ciphertext   CredentialVault-encrypted pending webhook secret, set only during a rotation.
	 * @param string|null $webhook_secret_pending_since        When the pending secret was generated.
	 * @param string      $webhook_registration_state          unregistered|registered|uncertain.
	 * @param string|null $webhook_last_attempt_at              Set on every register/rotate/retry/rollback attempt.
	 * @param int|null    $telegram_bot_id                     From getMe.
	 * @param string|null $telegram_username                   From getMe.
	 * @param BotStatus   $status                               The bot's own operational status.
	 * @param string|null $webhook_registered_at                Set when webhook_registration_state becomes 'registered'.
	 * @param string      $created_at                           Creation timestamp.
	 * @param string      $updated_at                           Last-modified timestamp.
	 */
	public function __construct(
		private readonly int $id,
		private readonly string $bot_uuid,
		private readonly string $name,
		private readonly string $token_ciphertext,
		private readonly string $webhook_secret_ciphertext,
		private readonly ?string $webhook_secret_pending_ciphertext,
		private readonly ?string $webhook_secret_pending_since,
		private readonly string $webhook_registration_state,
		private readonly ?string $webhook_last_attempt_at,
		private readonly ?int $telegram_bot_id,
		private readonly ?string $telegram_username,
		private readonly BotStatus $status,
		private readonly ?string $webhook_registered_at,
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
	 * Opaque, non-secret webhook route identifier.
	 *
	 * @return string
	 */
	public function bot_uuid(): string {
		return $this->bot_uuid;
	}

	/**
	 * Admin-facing label.
	 *
	 * @return string
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * CredentialVault-encrypted bot token. Never render this on any admin surface.
	 *
	 * @return string
	 */
	public function token_ciphertext(): string {
		return $this->token_ciphertext;
	}

	/**
	 * CredentialVault-encrypted active webhook secret. Never render this on any admin surface.
	 *
	 * @return string
	 */
	public function webhook_secret_ciphertext(): string {
		return $this->webhook_secret_ciphertext;
	}

	/**
	 * CredentialVault-encrypted pending webhook secret, set only during a rotation.
	 *
	 * @return string|null
	 */
	public function webhook_secret_pending_ciphertext(): ?string {
		return $this->webhook_secret_pending_ciphertext;
	}

	/**
	 * Whether a rotation is currently in progress.
	 *
	 * @return bool
	 */
	public function has_pending_secret(): bool {
		return null !== $this->webhook_secret_pending_ciphertext;
	}

	/**
	 * When the pending secret was generated.
	 *
	 * @return string|null
	 */
	public function webhook_secret_pending_since(): ?string {
		return $this->webhook_secret_pending_since;
	}

	/**
	 * The webhook registration state: unregistered|registered|uncertain.
	 *
	 * @return string
	 */
	public function webhook_registration_state(): string {
		return $this->webhook_registration_state;
	}

	/**
	 * Set on every register/rotate/retry/rollback attempt.
	 *
	 * @return string|null
	 */
	public function webhook_last_attempt_at(): ?string {
		return $this->webhook_last_attempt_at;
	}

	/**
	 * From getMe.
	 *
	 * @return int|null
	 */
	public function telegram_bot_id(): ?int {
		return $this->telegram_bot_id;
	}

	/**
	 * From getMe.
	 *
	 * @return string|null
	 */
	public function telegram_username(): ?string {
		return $this->telegram_username;
	}

	/**
	 * The bot's own operational status.
	 *
	 * @return BotStatus
	 */
	public function status(): BotStatus {
		return $this->status;
	}

	/**
	 * Set when webhook_registration_state becomes 'registered'.
	 *
	 * @return string|null
	 */
	public function webhook_registered_at(): ?string {
		return $this->webhook_registered_at;
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
}
