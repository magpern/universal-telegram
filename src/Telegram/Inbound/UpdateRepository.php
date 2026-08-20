<?php
/**
 * Inbound update persistence.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Telegram\Inbound;

use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Persistence\SchemaHealth;

/**
 * Metadata-only receipt of inbound updates. No message text is ever
 * persisted (docs/adr/0013, A3). Deduplication is enforced by the
 * database's own UNIQUE(bot_id, update_id) constraint via INSERT IGNORE —
 * a duplicate is a fast, silent no-op, never an exception.
 */
final class UpdateRepository {

	/**
	 * Constructor.
	 *
	 * @param SchemaHealth $schema_health Checked before every operation.
	 */
	public function __construct( private readonly SchemaHealth $schema_health ) {}

	/**
	 * Records one update's metadata. A repeat of the same (bot_id,
	 * update_id) is silently ignored — never a second row, never an error.
	 *
	 * @param int         $bot_id            The receiving bot's primary key.
	 * @param int         $update_id          Telegram's own update identifier.
	 * @param UpdateType  $update_type        The update's type, or UNSUPPORTED.
	 * @param string|null $chat_id            Metadata only, no text.
	 * @param int|null    $message_thread_id   Metadata only.
	 *
	 * @return bool True if this was a newly recorded update (not a repeat),
	 *               regardless of whether the schema was available at all.
	 */
	public function record( int $bot_id, int $update_id, UpdateType $update_type, ?string $chat_id, ?int $message_thread_id ): bool {
		if ( ! $this->schema_health->is_available() ) {
			return false;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::INBOUND_UPDATES_TABLE;

		// chat_id/message_thread_id are rendered as explicit SQL NULL
		// literals when absent, rather than a %s/%d placeholder for a null
		// argument, which is not reliably rendered as SQL NULL across
		// supported WordPress core versions.
		$chat_id_sql           = null === $chat_id ? 'NULL' : $wpdb->prepare( '%s', $chat_id );
		$message_thread_id_sql = null === $message_thread_id ? 'NULL' : $wpdb->prepare( '%d', $message_thread_id );

		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (bot_id, update_id, update_type, chat_id, message_thread_id, received_at) " // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				. "VALUES (%d, %d, %s, {$chat_id_sql}, {$message_thread_id_sql}, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- each fragment is itself already a NULL literal or a prepare()d value.
				$bot_id,
				$update_id,
				$update_type->value,
				current_time( 'mysql', true )
			)
		);

		return $wpdb->rows_affected > 0;
	}

	/**
	 * The timestamp of the most recently received update for a bot, if any
	 * — the connection-test signal surfaced in the bot management screen.
	 *
	 * @param int $bot_id The bot's primary key.
	 *
	 * @return string|null
	 */
	public function last_received_at( int $bot_id ): ?string {
		if ( ! $this->schema_health->is_available() ) {
			return null;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::INBOUND_UPDATES_TABLE;
		$value = $wpdb->get_var(
			$wpdb->prepare( "SELECT MAX(received_at) FROM {$table} WHERE bot_id = %d", $bot_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		return null === $value ? null : (string) $value;
	}
}
