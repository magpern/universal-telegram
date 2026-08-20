<?php
/**
 * Destination persistence.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Telegram\Configuration;

use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Persistence\SchemaHealth;

/**
 * CRUD for destinations, owned by a bot. Checks SchemaHealth::is_available()
 * at its own point of use (docs/adr/0007). The unique(bot_id, chat_id,
 * message_thread_id) database constraint (WP1) is the schema-level
 * enforcement of "no duplicate destination"; Destination's own constructor
 * (WP3) is the enforcement of "message_thread_id only for supergroup".
 */
final class DestinationRepository {

	/**
	 * Constructor.
	 *
	 * @param SchemaHealth $schema_health Checked before every operation.
	 */
	public function __construct( private readonly SchemaHealth $schema_health ) {}

	/**
	 * Creates a new destination.
	 *
	 * @param int             $bot_id             The owning bot's primary key.
	 * @param DestinationKind $kind               The Telegram chat kind.
	 * @param string          $chat_id             Telegram's own chat identifier.
	 * @param int|null        $message_thread_id   Forum topic ID; only valid when $kind is SUPERGROUP.
	 * @param string          $label               Admin-facing name.
	 *
	 * @return Destination|null Null if the schema is unavailable or the insert failed.
	 *
	 * @throws InvalidDestinationException If message_thread_id is set on any kind other than SUPERGROUP.
	 */
	public function create( int $bot_id, DestinationKind $kind, string $chat_id, ?int $message_thread_id, string $label ): ?Destination {
		// Validate before ever touching the database, matching JobEnvelope's
		// own fail-closed-at-construction precedent.
		new Destination( 0, $bot_id, $kind, $chat_id, $message_thread_id, $label, true, '1970-01-01 00:00:00' );

		if ( ! $this->schema_health->is_available() ) {
			return null;
		}

		global $wpdb;

		$table    = $wpdb->prefix . Migrator::DESTINATIONS_TABLE;
		$inserted = $wpdb->insert(
			$table,
			array(
				'bot_id'            => $bot_id,
				'kind'              => $kind->value,
				'chat_id'           => $chat_id,
				'message_thread_id' => $message_thread_id,
				'label'             => $label,
				'enabled'           => 1,
				'created_at'        => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%d', '%s', '%d', '%s' )
		);

		if ( false === $inserted ) {
			return null;
		}

		return $this->find( (int) $wpdb->insert_id );
	}

	/**
	 * Finds a destination by primary key.
	 *
	 * @param int $id The destination's primary key.
	 *
	 * @return Destination|null
	 */
	public function find( int $id ): ?Destination {
		if ( ! $this->schema_health->is_available() ) {
			return null;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::DESTINATIONS_TABLE;
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return null === $row ? null : $this->hydrate( $row );
	}

	/**
	 * Every destination belonging to a bot.
	 *
	 * @param int $bot_id The owning bot's primary key.
	 *
	 * @return array<int, Destination>
	 */
	public function for_bot( int $bot_id ): array {
		if ( ! $this->schema_health->is_available() ) {
			return array();
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::DESTINATIONS_TABLE;
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE bot_id = %d ORDER BY id ASC", $bot_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_map( array( $this, 'hydrate' ), null === $rows ? array() : $rows );
	}

	/**
	 * Enables or disables a destination.
	 *
	 * @param int  $id      The destination's primary key.
	 * @param bool $enabled The new enabled state.
	 *
	 * @return bool
	 */
	public function set_enabled( int $id, bool $enabled ): bool {
		if ( ! $this->schema_health->is_available() ) {
			return false;
		}

		global $wpdb;

		$table   = $wpdb->prefix . Migrator::DESTINATIONS_TABLE;
		$updated = $wpdb->update( $table, array( 'enabled' => $enabled ? 1 : 0 ), array( 'id' => $id ), array( '%d' ), array( '%d' ) );

		return false !== $updated;
	}

	/**
	 * Deletes a destination.
	 *
	 * @param int $id The destination's primary key.
	 *
	 * @return bool
	 */
	public function delete( int $id ): bool {
		if ( ! $this->schema_health->is_available() ) {
			return false;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::DESTINATIONS_TABLE;

		return false !== $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Deletes every destination belonging to a bot. Used when a bot itself
	 * is deleted.
	 *
	 * @param int $bot_id The owning bot's primary key.
	 *
	 * @return bool
	 */
	public function delete_for_bot( int $bot_id ): bool {
		if ( ! $this->schema_health->is_available() ) {
			return false;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::DESTINATIONS_TABLE;

		return false !== $wpdb->delete( $table, array( 'bot_id' => $bot_id ), array( '%d' ) );
	}

	/**
	 * Hydrates one database row into a Destination.
	 *
	 * @param array<string, mixed> $row The raw database row.
	 *
	 * @return Destination
	 */
	private function hydrate( array $row ): Destination {
		return new Destination(
			(int) $row['id'],
			(int) $row['bot_id'],
			DestinationKind::from( (string) $row['kind'] ),
			(string) $row['chat_id'],
			null === $row['message_thread_id'] ? null : (int) $row['message_thread_id'],
			(string) $row['label'],
			'1' === (string) $row['enabled'] || 1 === (int) $row['enabled'],
			(string) $row['created_at']
		);
	}
}
