<?php
/**
 * UT-owned Support Chat channel binding persistence.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\SupportChatAdapter;

use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Persistence\SchemaHealth;

/**
 * Binding CRUD with uniqueness on conversation UUID, topic identity, and
 * ensure idempotency key. Never reads or writes Support Chat tables.
 */
final class ChannelBindingRepository {

	/**
	 * Constructor.
	 *
	 * @param SchemaHealth $schema_health Checked before every operation.
	 */
	public function __construct( private readonly SchemaHealth $schema_health ) {}

	/**
	 * Finds a binding by opaque UUID.
	 *
	 * @param string $binding_uuid Opaque channel_case_ref.
	 */
	public function find_by_uuid( string $binding_uuid ): ?ChannelBinding {
		if ( ! $this->schema_health->is_available() ) {
			return null;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::SUPPORT_CHAT_BINDINGS_TABLE;
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE binding_uuid = %s", $binding_uuid ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return null === $row ? null : $this->hydrate( $row );
	}

	/**
	 * Finds a binding by Support Chat conversation UUID.
	 *
	 * @param string $conversation_uuid Support Chat conversation UUID.
	 */
	public function find_by_conversation_uuid( string $conversation_uuid ): ?ChannelBinding {
		if ( ! $this->schema_health->is_available() ) {
			return null;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::SUPPORT_CHAT_BINDINGS_TABLE;
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE support_conversation_uuid = %s", $conversation_uuid ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return null === $row ? null : $this->hydrate( $row );
	}

	/**
	 * Finds a binding by ensure idempotency key.
	 *
	 * @param string $idempotency_key Ensure idempotency key.
	 */
	public function find_by_ensure_key( string $idempotency_key ): ?ChannelBinding {
		if ( ! $this->schema_health->is_available() ) {
			return null;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::SUPPORT_CHAT_BINDINGS_TABLE;
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE ensure_idempotency_key = %s", $idempotency_key ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return null === $row ? null : $this->hydrate( $row );
	}

	/**
	 * Finds a binding by bot + forum topic id.
	 *
	 * @param int $bot_id            Bot primary key.
	 * @param int $telegram_topic_id Forum topic id.
	 */
	public function find_by_bot_topic( int $bot_id, int $telegram_topic_id ): ?ChannelBinding {
		if ( ! $this->schema_health->is_available() ) {
			return null;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::SUPPORT_CHAT_BINDINGS_TABLE;
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE bot_id = %d AND telegram_topic_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$bot_id,
				$telegram_topic_id
			),
			ARRAY_A
		);

		return null === $row ? null : $this->hydrate( $row );
	}

	/**
	 * Inserts a new active binding.
	 *
	 * @param string $binding_uuid              Opaque channel_case_ref.
	 * @param string $support_conversation_uuid Support Chat conversation UUID.
	 * @param string $ensure_idempotency_key    Ensure idempotency key.
	 * @param int    $bot_id                    Bot primary key.
	 * @param int    $destination_id            Topic destination primary key.
	 * @param int    $telegram_topic_id         Forum topic id.
	 */
	public function create(
		string $binding_uuid,
		string $support_conversation_uuid,
		string $ensure_idempotency_key,
		int $bot_id,
		int $destination_id,
		int $telegram_topic_id
	): ?ChannelBinding {
		if ( ! $this->schema_health->is_available() ) {
			return null;
		}

		global $wpdb;

		$now   = current_time( 'mysql', true );
		$table = $wpdb->prefix . Migrator::SUPPORT_CHAT_BINDINGS_TABLE;
		$ok    = $wpdb->insert(
			$table,
			array(
				'binding_uuid'              => $binding_uuid,
				'support_conversation_uuid' => $support_conversation_uuid,
				'ensure_idempotency_key'    => $ensure_idempotency_key,
				'bot_id'                    => $bot_id,
				'destination_id'            => $destination_id,
				'telegram_topic_id'         => $telegram_topic_id,
				'cas_version'               => 1,
				'status'                    => ChannelBinding::STATUS_ACTIVE,
				'created_at'                => $now,
				'updated_at'                => $now,
			),
			array( '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%s' )
		);

		if ( false === $ok ) {
			return null;
		}

		return $this->find_by_uuid( $binding_uuid );
	}

	/**
	 * Sets binding status.
	 *
	 * @param string $binding_uuid Binding UUID.
	 * @param string $status       active|unavailable|closed.
	 */
	public function set_status( string $binding_uuid, string $status ): bool {
		if ( ! $this->schema_health->is_available() ) {
			return false;
		}

		if ( ! in_array( $status, array( ChannelBinding::STATUS_ACTIVE, ChannelBinding::STATUS_UNAVAILABLE, ChannelBinding::STATUS_CLOSED ), true ) ) {
			return false;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::SUPPORT_CHAT_BINDINGS_TABLE;
		$ok    = $wpdb->update(
			$table,
			array(
				'status'     => $status,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'binding_uuid' => $binding_uuid ),
			array( '%s', '%s' ),
			array( '%s' )
		);

		return false !== $ok;
	}

	/**
	 * Marks every active binding unavailable (adapter deactivation path).
	 *
	 * @return int Number of rows updated.
	 */
	public function mark_all_active_unavailable(): int {
		if ( ! $this->schema_health->is_available() ) {
			return 0;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::SUPPORT_CHAT_BINDINGS_TABLE;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s, updated_at = %s WHERE status = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				ChannelBinding::STATUS_UNAVAILABLE,
				current_time( 'mysql', true ),
				ChannelBinding::STATUS_ACTIVE
			)
		);

		return false === $updated ? 0 : (int) $updated;
	}

	/**
	 * Lists active binding UUIDs (for deactivation reporting).
	 *
	 * @param int $limit Max rows.
	 *
	 * @return array<int, string>
	 */
	public function list_active_binding_uuids( int $limit = 100 ): array {
		if ( ! $this->schema_health->is_available() ) {
			return array();
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::SUPPORT_CHAT_BINDINGS_TABLE;
		$rows  = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT binding_uuid FROM {$table} WHERE status = %s ORDER BY id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				ChannelBinding::STATUS_ACTIVE,
				$limit
			)
		);

		return is_array( $rows ) ? array_map( 'strval', $rows ) : array();
	}

	/**
	 * Records the last delivered outbound idempotency key when CAS matches.
	 *
	 * @param string $binding_uuid   Binding UUID.
	 * @param string $idempotency_key Deliver/backfill key.
	 * @param int    $expected_cas   Expected cas_version.
	 */
	public function record_delivered_key( string $binding_uuid, string $idempotency_key, int $expected_cas ): bool {
		if ( ! $this->schema_health->is_available() ) {
			return false;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::SUPPORT_CHAT_BINDINGS_TABLE;
		$ok    = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET last_delivered_message_key = %s, cas_version = cas_version + 1, updated_at = %s WHERE binding_uuid = %s AND cas_version = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$idempotency_key,
				current_time( 'mysql', true ),
				$binding_uuid,
				$expected_cas
			)
		);

		return false !== $ok && (int) $ok > 0;
	}

	/**
	 * Records the last ingested Telegram update id when CAS matches.
	 *
	 * @param string $binding_uuid Binding UUID.
	 * @param int    $update_id    Telegram update_id.
	 * @param int    $expected_cas Expected cas_version.
	 */
	public function record_ingest_update_id( string $binding_uuid, int $update_id, int $expected_cas ): bool {
		if ( ! $this->schema_health->is_available() ) {
			return false;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::SUPPORT_CHAT_BINDINGS_TABLE;
		$ok    = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET last_ingest_update_id = %d, cas_version = cas_version + 1, updated_at = %s WHERE binding_uuid = %s AND cas_version = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$update_id,
				current_time( 'mysql', true ),
				$binding_uuid,
				$expected_cas
			)
		);

		return false !== $ok && (int) $ok > 0;
	}

	/**
	 * Counts bindings by status.
	 *
	 * @return array<string, int>
	 */
	public function count_by_status(): array {
		$counts = array(
			ChannelBinding::STATUS_ACTIVE      => 0,
			ChannelBinding::STATUS_UNAVAILABLE => 0,
			ChannelBinding::STATUS_CLOSED      => 0,
		);

		if ( ! $this->schema_health->is_available() ) {
			return $counts;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::SUPPORT_CHAT_BINDINGS_TABLE;
		$rows  = $wpdb->get_results( "SELECT status, COUNT(*) AS c FROM {$table} GROUP BY status", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! is_array( $rows ) ) {
			return $counts;
		}

		foreach ( $rows as $row ) {
			$status = (string) ( $row['status'] ?? '' );
			if ( isset( $counts[ $status ] ) ) {
				$counts[ $status ] = (int) $row['c'];
			}
		}

		return $counts;
	}

	/**
	 * Hydrates a binding value object from a database row.
	 *
	 * @param array<string, mixed> $row Database row.
	 */
	private function hydrate( array $row ): ChannelBinding {
		return new ChannelBinding(
			(int) $row['id'],
			(string) $row['binding_uuid'],
			(string) $row['support_conversation_uuid'],
			(string) $row['ensure_idempotency_key'],
			(int) $row['bot_id'],
			(int) $row['destination_id'],
			(int) $row['telegram_topic_id'],
			(int) $row['cas_version'],
			(string) $row['status'],
			isset( $row['last_delivered_message_key'] ) && is_string( $row['last_delivered_message_key'] ) ? $row['last_delivered_message_key'] : null,
			array_key_exists( 'last_ingest_update_id', $row ) && null !== $row['last_ingest_update_id'] && '' !== $row['last_ingest_update_id']
				? (int) $row['last_ingest_update_id']
				: null,
			(string) $row['created_at'],
			(string) $row['updated_at']
		);
	}
}
