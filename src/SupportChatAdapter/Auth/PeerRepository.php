<?php
/**
 * Paired Support Chat peer persistence (ADR-0007 §2).
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\SupportChatAdapter\Auth;

use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Persistence\SchemaHealth;

/**
 * CRUD for the peer key store. Never persists a private key. This plugin
 * ever pairs exactly one peer slug in practice (`universal-support-chat`),
 * but the schema and API are kept peer-slug generic rather than hard-coded,
 * matching Support Chat's own PeerRepository shape.
 */
class PeerRepository {

	/**
	 * Constructor.
	 *
	 * @param SchemaHealth $schema_health Schema availability gate.
	 */
	public function __construct( private readonly SchemaHealth $schema_health ) {}

	/**
	 * Finds a peer by its slug.
	 *
	 * @param string $peer_id Peer slug.
	 */
	public function find_by_peer_id( string $peer_id ): ?PeerRecord {
		if ( ! $this->schema_health->is_available() ) {
			return null;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::SUPPORT_CHAT_PEERS_TABLE;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE peer_id = %s LIMIT 1",
				$peer_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_array( $row ) ? PeerRecord::from_row( $row ) : null;
	}

	/**
	 * Lists every paired peer, newest first.
	 *
	 * @return array<int, PeerRecord>
	 */
	public function list_all(): array {
		if ( ! $this->schema_health->is_available() ) {
			return array();
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::SUPPORT_CHAT_PEERS_TABLE;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name.
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC", ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map( static fn( array $row ) => PeerRecord::from_row( $row ), $rows );
	}

	/**
	 * Inserts a brand-new peer record. Fails if peer_id already exists.
	 *
	 * @param string             $peer_id                  Peer slug.
	 * @param string             $public_key_base64        Base64 public key.
	 * @param string             $key_id                   Peer key ID.
	 * @param array<int, string> $allowed_operations       Permitted operations.
	 * @param string|null        $required_peer_capability Required peer capability.
	 * @param string|null        $expires_at               Expiry (UTC mysql), or null for no expiry.
	 */
	public function create(
		string $peer_id,
		string $public_key_base64,
		string $key_id,
		array $allowed_operations,
		?string $required_peer_capability,
		?string $expires_at = null
	): ?PeerRecord {
		if ( ! $this->schema_health->is_available() ) {
			return null;
		}

		global $wpdb;

		$table    = $wpdb->prefix . Migrator::SUPPORT_CHAT_PEERS_TABLE;
		$now      = current_time( 'mysql', true );
		$data     = array(
			'peer_id'                  => $peer_id,
			'public_key'               => $public_key_base64,
			'key_id'                   => $key_id,
			'allowed_operations'       => wp_json_encode( array_values( $allowed_operations ) ),
			'required_peer_capability' => $required_peer_capability,
			'status'                   => PeerRecord::STATUS_ACTIVE,
			'created_at'               => $now,
			'expires_at'               => $expires_at,
		);
		$formats  = array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' );
		$inserted = $wpdb->insert( $table, $data, $formats );

		if ( false === $inserted ) {
			return null;
		}

		return $this->find_by_peer_id( $peer_id );
	}

	/**
	 * Replaces an existing peer's public key/key ID/allow-list (rotation or
	 * confirmed re-pairing). Callers are responsible for the
	 * confirm-before-replace gate (ADR-0007 §2); this method always replaces.
	 *
	 * @param string             $peer_id                  Peer slug.
	 * @param string             $public_key_base64        Base64 public key.
	 * @param string             $key_id                   Peer key ID.
	 * @param array<int, string> $allowed_operations       Permitted operations.
	 * @param string|null        $required_peer_capability Required peer capability.
	 * @param string|null        $expires_at               Expiry (UTC mysql), or null for no expiry.
	 */
	public function replace_key(
		string $peer_id,
		string $public_key_base64,
		string $key_id,
		array $allowed_operations,
		?string $required_peer_capability,
		?string $expires_at = null
	): ?PeerRecord {
		if ( ! $this->schema_health->is_available() ) {
			return null;
		}

		global $wpdb;

		$table   = $wpdb->prefix . Migrator::SUPPORT_CHAT_PEERS_TABLE;
		$updated = $wpdb->update(
			$table,
			array(
				'public_key'               => $public_key_base64,
				'key_id'                   => $key_id,
				'allowed_operations'       => wp_json_encode( array_values( $allowed_operations ) ),
				'required_peer_capability' => $required_peer_capability,
				'status'                   => PeerRecord::STATUS_ACTIVE,
				'last_rotated_at'          => current_time( 'mysql', true ),
				'revoked_at'               => null,
				'expires_at'               => $expires_at,
			),
			array( 'peer_id' => $peer_id ),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ),
			array( '%s' )
		);

		if ( false === $updated ) {
			return null;
		}

		return $this->find_by_peer_id( $peer_id );
	}

	/**
	 * Sets a peer's stored status (active/disabled/revoked).
	 *
	 * @param string $peer_id Peer slug.
	 * @param string $status  One of the PeerRecord::STATUS_* constants.
	 */
	public function set_status( string $peer_id, string $status ): bool {
		if ( ! $this->schema_health->is_available() ) {
			return false;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::SUPPORT_CHAT_PEERS_TABLE;
		$data  = array( 'status' => $status );

		if ( PeerRecord::STATUS_REVOKED === $status ) {
			$data['revoked_at'] = current_time( 'mysql', true );
		}

		$updated = $wpdb->update(
			$table,
			$data,
			array( 'peer_id' => $peer_id ),
			null,
			array( '%s' )
		);

		return false !== $updated;
	}

	/**
	 * Best-effort touch of last_used_at after a verified authenticated call.
	 *
	 * @param string $peer_id Peer slug.
	 */
	public function touch_last_used( string $peer_id ): void {
		if ( ! $this->schema_health->is_available() ) {
			return;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::SUPPORT_CHAT_PEERS_TABLE;
		$wpdb->update(
			$table,
			array( 'last_used_at' => current_time( 'mysql', true ) ),
			array( 'peer_id' => $peer_id ),
			array( '%s' ),
			array( '%s' )
		);
	}
}
