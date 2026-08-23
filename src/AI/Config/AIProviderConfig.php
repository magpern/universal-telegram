<?php
/**
 * AI provider configuration value object.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\AI\Config;

/**
 * Immutable read model of the single row (`id=1`) of
 * universal_telegram_ai_config (docs/adr/0028 decision 3). Never carries
 * plaintext credential material — only the ciphertext envelope, which
 * AIProviderRepository decrypts on demand for the outbound provider call
 * only. No admin surface may ever render api_key_ciphertext().
 */
final class AIProviderConfig {

	/**
	 * Constructor.
	 *
	 * @param string      $provider           Fixed 'openai' in M09.
	 * @param string      $model              Administrator-entered, bounded model identifier.
	 * @param string|null $api_key_ciphertext CredentialVault-encrypted API key, null until first configured.
	 * @param bool        $enabled            Whether AI draft generation is enabled site-wide.
	 * @param string      $ack_policy_version Current disclosure-text version tag.
	 * @param string      $ack_text           Current disclosure copy shown in the chat widget.
	 * @param string      $created_at         Creation timestamp.
	 * @param string      $updated_at         Last-modified timestamp.
	 */
	public function __construct(
		private readonly string $provider,
		private readonly string $model,
		private readonly ?string $api_key_ciphertext,
		private readonly bool $enabled,
		private readonly string $ack_policy_version,
		private readonly string $ack_text,
		private readonly string $created_at,
		private readonly string $updated_at
	) {}

	/**
	 * Fixed 'openai' in M09.
	 *
	 * @return string
	 */
	public function provider(): string {
		return $this->provider;
	}

	/**
	 * Administrator-entered, bounded model identifier.
	 *
	 * @return string
	 */
	public function model(): string {
		return $this->model;
	}

	/**
	 * CredentialVault-encrypted API key, null until first configured.
	 *
	 * @return ?string
	 */
	public function api_key_ciphertext(): ?string {
		return $this->api_key_ciphertext;
	}

	/**
	 * Whether a non-empty credential is currently stored.
	 *
	 * @return bool
	 */
	public function has_credential(): bool {
		return null !== $this->api_key_ciphertext && '' !== $this->api_key_ciphertext;
	}

	/**
	 * Whether AI draft generation is enabled site-wide.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		return $this->enabled;
	}

	/**
	 * Whether AI is enabled AND has the non-empty credential and bounded
	 * model identifier the charter requires before any provider call may
	 * be attempted (docs/milestones/m09-ai-draft-assistant.md).
	 */
	public function is_ready(): bool {
		return $this->enabled && $this->has_credential() && '' !== $this->model;
	}

	/**
	 * Current disclosure-text version tag.
	 *
	 * @return string
	 */
	public function ack_policy_version(): string {
		return $this->ack_policy_version;
	}

	/**
	 * Current disclosure copy shown in the chat widget.
	 *
	 * @return string
	 */
	public function ack_text(): string {
		return $this->ack_text;
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
