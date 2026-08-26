<?php
/**
 * One buffered webhook update.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Migration;

/**
 * A row of `{$wpdb->prefix}universal_telegram_quiescence_deferred_updates`
 * (docs/adr/0040 §4, Table 3). `payload_ciphertext` is intentionally never
 * exposed here — this value object is safe to pass to `status`/CLI/
 * diagnostics/audit call sites, none of which may ever see plaintext or
 * ciphertext content.
 */
final class DeferredUpdateRecord {

	/**
	 * Constructor.
	 *
	 * @param int         $id          The row's own primary key.
	 * @param int         $bot_id      The receiving bot.
	 * @param int         $update_id   Telegram's own update_id.
	 * @param string      $update_type The update's type (metadata only).
	 * @param string      $received_at When this row was buffered (MySQL datetime, UTC).
	 * @param string|null $replayed_at When this row was successfully replayed, or null.
	 */
	public function __construct(
		private readonly int $id,
		private readonly int $bot_id,
		private readonly int $update_id,
		private readonly string $update_type,
		private readonly string $received_at,
		private readonly ?string $replayed_at
	) {}

	/**
	 * The row's own primary key.
	 *
	 * @return int
	 */
	public function id(): int {
		return $this->id;
	}

	/**
	 * The receiving bot.
	 *
	 * @return int
	 */
	public function bot_id(): int {
		return $this->bot_id;
	}

	/**
	 * Telegram's own update_id.
	 *
	 * @return int
	 */
	public function update_id(): int {
		return $this->update_id;
	}

	/**
	 * The update's type (metadata only, never content).
	 *
	 * @return string
	 */
	public function update_type(): string {
		return $this->update_type;
	}

	/**
	 * When this row was buffered (MySQL datetime, UTC).
	 *
	 * @return string
	 */
	public function received_at(): string {
		return $this->received_at;
	}

	/**
	 * When this row was successfully replayed, or null if still pending.
	 *
	 * @return string|null
	 */
	public function replayed_at(): ?string {
		return $this->replayed_at;
	}
}
