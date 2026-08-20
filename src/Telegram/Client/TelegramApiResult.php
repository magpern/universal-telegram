<?php
/**
 * Telegram API response value object.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Telegram\Client;

/**
 * The uniform shape every TelegramApiClient method returns. is_network_error()
 * is true only when no HTTP response was received at all (connection reset,
 * DNS failure, timeout) — the one specific ambiguous-outcome signal ADR-0014
 * uses to set possible_duplicate_delivery; a definite HTTP error response is
 * never a network error, regardless of its status code.
 */
final class TelegramApiResult {

	/**
	 * Constructor.
	 *
	 * @param bool                      $ok                Telegram's own top-level "ok" field, or false for a network-transport failure.
	 * @param int|null                  $http_status       The HTTP status code, or null when no response was received at all.
	 * @param array<string, mixed>|null $result            Telegram's own "result" field, present only on success.
	 * @param int|null                  $error_code        Telegram's own "error_code" field.
	 * @param string|null               $description       Telegram's own "description" field.
	 * @param array<string, mixed>|null $parameters        Telegram's own "parameters" field (may carry retry_after).
	 * @param bool                      $is_network_error  True only when no HTTP response was received at all.
	 */
	public function __construct(
		private readonly bool $ok,
		private readonly ?int $http_status,
		private readonly ?array $result,
		private readonly ?int $error_code,
		private readonly ?string $description,
		private readonly ?array $parameters,
		private readonly bool $is_network_error
	) {}

	/**
	 * Telegram's own top-level "ok" field, or false for a network-transport failure.
	 *
	 * @return bool
	 */
	public function ok(): bool {
		return $this->ok;
	}

	/**
	 * The HTTP status code, or null when no response was received at all.
	 *
	 * @return int|null
	 */
	public function http_status(): ?int {
		return $this->http_status;
	}

	/**
	 * Telegram's own "result" field, present only on success.
	 *
	 * @return array<string, mixed>|null
	 */
	public function result(): ?array {
		return $this->result;
	}

	/**
	 * Telegram's own "error_code" field.
	 *
	 * @return int|null
	 */
	public function error_code(): ?int {
		return $this->error_code;
	}

	/**
	 * Telegram's own "description" field.
	 *
	 * @return string|null
	 */
	public function description(): ?string {
		return $this->description;
	}

	/**
	 * Telegram's own "parameters" field (may carry retry_after).
	 *
	 * @return array<string, mixed>|null
	 */
	public function parameters(): ?array {
		return $this->parameters;
	}

	/**
	 * The optional retry_after seconds from a 429 response's "parameters"
	 * field, if present and a positive integer.
	 *
	 * @return int|null
	 */
	public function retry_after(): ?int {
		if ( null === $this->parameters || ! isset( $this->parameters['retry_after'] ) ) {
			return null;
		}

		$value = $this->parameters['retry_after'];

		if ( ! is_int( $value ) && ! ( is_string( $value ) && ctype_digit( $value ) ) ) {
			return null;
		}

		$int_value = (int) $value;

		return $int_value > 0 ? $int_value : null;
	}

	/**
	 * True only when no HTTP response was received at all — the specific
	 * ambiguous-outcome signal (docs/adr/0014).
	 *
	 * @return bool
	 */
	public function is_network_error(): bool {
		return $this->is_network_error;
	}
}
