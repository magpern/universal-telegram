<?php
/**
 * Support Chat channel binding row.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\SupportChatAdapter;

/**
 * One UT-owned binding between a Support Chat conversation and a Telegram
 * forum topic. Support Chat stores only the opaque binding_uuid as
 * channel_case_ref.
 */
final class ChannelBinding {

	public const STATUS_ACTIVE      = 'active';
	public const STATUS_UNAVAILABLE = 'unavailable';
	public const STATUS_CLOSED      = 'closed';

	/**
	 * Non-routing status written only by SC-M03 work package 5's
	 * LegacyBindingImportServiceV1 (Support Chat ADR-0009, ADR-0041). Falls
	 * into is_active()'s existing "not active" branch by construction, so
	 * inbound/outbound routing never consults a prepared row without any
	 * change to is_active(), try_handle(), or DeliverMessageService.
	 */
	public const STATUS_PREPARED = 'prepared';

	/**
	 * Constructor.
	 *
	 * @param int         $id                         Primary key.
	 * @param string      $binding_uuid               Opaque channel_case_ref.
	 * @param string      $support_conversation_uuid  Support Chat conversation UUID.
	 * @param string      $ensure_idempotency_key     Ensure idempotency key.
	 * @param int         $bot_id                     Bot primary key.
	 * @param int         $destination_id             Topic destination primary key.
	 * @param int         $telegram_topic_id          Forum topic id.
	 * @param int         $cas_version                Optimistic concurrency version.
	 * @param string      $status                     active|unavailable|closed.
	 * @param string|null $last_delivered_message_key Last deliver/backfill idempotency key.
	 * @param int|null    $last_ingest_update_id      Last Telegram update_id ingested.
	 * @param string      $created_at                 UTC datetime.
	 * @param string      $updated_at                 UTC datetime.
	 */
	public function __construct(
		private readonly int $id,
		private readonly string $binding_uuid,
		private readonly string $support_conversation_uuid,
		private readonly string $ensure_idempotency_key,
		private readonly int $bot_id,
		private readonly int $destination_id,
		private readonly int $telegram_topic_id,
		private readonly int $cas_version,
		private readonly string $status,
		private readonly ?string $last_delivered_message_key,
		private readonly ?int $last_ingest_update_id,
		private readonly string $created_at,
		private readonly string $updated_at
	) {}

	/**
	 * Primary key.
	 */
	public function id(): int {
		return $this->id;
	}

	/**
	 * Opaque channel_case_ref UUID.
	 */
	public function binding_uuid(): string {
		return $this->binding_uuid;
	}

	/**
	 * Support Chat conversation UUID.
	 */
	public function support_conversation_uuid(): string {
		return $this->support_conversation_uuid;
	}

	/**
	 * Ensure idempotency key.
	 */
	public function ensure_idempotency_key(): string {
		return $this->ensure_idempotency_key;
	}

	/**
	 * Bot primary key.
	 */
	public function bot_id(): int {
		return $this->bot_id;
	}

	/**
	 * Topic destination primary key.
	 */
	public function destination_id(): int {
		return $this->destination_id;
	}

	/**
	 * Telegram forum topic id.
	 */
	public function telegram_topic_id(): int {
		return $this->telegram_topic_id;
	}

	/**
	 * Optimistic concurrency version.
	 */
	public function cas_version(): int {
		return $this->cas_version;
	}

	/**
	 * Binding status.
	 */
	public function status(): string {
		return $this->status;
	}

	/**
	 * Last deliver/backfill idempotency key.
	 */
	public function last_delivered_message_key(): ?string {
		return $this->last_delivered_message_key;
	}

	/**
	 * Last ingested Telegram update_id.
	 */
	public function last_ingest_update_id(): ?int {
		return $this->last_ingest_update_id;
	}

	/**
	 * Created-at UTC datetime.
	 */
	public function created_at(): string {
		return $this->created_at;
	}

	/**
	 * Updated-at UTC datetime.
	 */
	public function updated_at(): string {
		return $this->updated_at;
	}

	/**
	 * Whether the binding is active.
	 */
	public function is_active(): bool {
		return self::STATUS_ACTIVE === $this->status;
	}
}
