<?php
/**
 * Provider-neutral AI completion result.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\AI\Provider;

/**
 * The uniform shape every AiProviderInterface::complete() call returns.
 * is_network_error() is true only when no HTTP response was received at
 * all — mirrors Telegram\Client\TelegramApiResult's exact precedent. Never
 * carries the raw provider error body (docs/adr/0028 decision 4) — only a
 * bounded http_status for classification.
 */
final class AiResult {

	/**
	 * Constructor.
	 *
	 * @param bool        $ok               Whether a completion was successfully produced.
	 * @param string|null $text             The completion text, truncated to the request's bound; present only when $ok.
	 * @param bool        $truncated        Whether the raw completion exceeded the bound and was cut.
	 * @param int|null    $http_status      The HTTP status code, or null when no response was received at all.
	 * @param bool        $is_network_error True only when no HTTP response was received at all.
	 */
	public function __construct(
		private readonly bool $ok,
		private readonly ?string $text,
		private readonly bool $truncated,
		private readonly ?int $http_status,
		private readonly bool $is_network_error
	) {}

	/**
	 * Whether a completion was successfully produced.
	 *
	 * @return bool
	 */
	public function ok(): bool {
		return $this->ok;
	}

	/**
	 * The completion text, truncated to the request's bound; present only when $ok.
	 *
	 * @return ?string
	 */
	public function text(): ?string {
		return $this->text;
	}

	/**
	 * Whether the raw completion exceeded the bound and was cut.
	 *
	 * @return bool
	 */
	public function truncated(): bool {
		return $this->truncated;
	}

	/**
	 * The HTTP status code, or null when no response was received at all.
	 *
	 * @return ?int
	 */
	public function http_status(): ?int {
		return $this->http_status;
	}

	/**
	 * True only when no HTTP response was received at all.
	 *
	 * @return bool
	 */
	public function is_network_error(): bool {
		return $this->is_network_error;
	}
}
