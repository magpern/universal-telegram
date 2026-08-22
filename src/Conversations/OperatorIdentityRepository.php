<?php
/**
 * Operator identity mapping persistence.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Conversations;

use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Persistence\SchemaHealth;

/**
 * CRUD for the manually maintained WordPress-user <-> Telegram numeric-id
 * identity mapping (M07, docs/adr/0026). Every row is created only through
 * a manual, MANAGE-gated admin action — never inferred from a webhook
 * payload or a Telegram username. telegram_user_id and telegram_username
 * are both SENSITIVE; this repository never logs either value.
 */
class OperatorIdentityRepository {

	/**
	 * Constructor.
	 *
	 * @param SchemaHealth $schema_health Checked before every operation.
	 */
	public function __construct(
		private readonly SchemaHealth $schema_health
	) {}

	/**
	 * Creates a mapping. Fails (returns null) on a duplicate wp_user_id or
	 * telegram_user_id, since both columns are unique — a WordPress user or
	 * a Telegram account may hold at most one mapping.
	 *
	 * @param int         $wp_user_id        The WordPress user to map.
	 * @param int         $telegram_user_id  The Telegram numeric sender id to map.
	 * @param string|null $telegram_username The Telegram username at mapping time, if known.
	 * @param int         $created_by        The WordPress user creating this mapping.
	 *
	 * @return OperatorIdentity|null
	 */
	public function create( int $wp_user_id, int $telegram_user_id, ?string $telegram_username, int $created_by ): ?OperatorIdentity {
		if ( ! $this->schema_health->is_available() ) {
			return null;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::OPERATOR_IDENTITIES_TABLE;

		$inserted = $wpdb->insert(
			$table,
			array(
				'wp_user_id'        => $wp_user_id,
				'telegram_user_id'  => $telegram_user_id,
				'telegram_username' => $telegram_username,
				'created_at'        => current_time( 'mysql', true ),
				'created_by'        => $created_by,
			),
			array( '%d', '%d', '%s', '%s', '%d' )
		);

		if ( false === $inserted ) {
			return null;
		}

		return $this->find( (int) $wpdb->insert_id );
	}

	/**
	 * Finds a mapping by primary key.
	 *
	 * @param int $id The mapping's primary key.
	 *
	 * @return OperatorIdentity|null
	 */
	public function find( int $id ): ?OperatorIdentity {
		if ( ! $this->schema_health->is_available() ) {
			return null;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::OPERATOR_IDENTITIES_TABLE;
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return null === $row ? null : $this->hydrate( $row );
	}

	/**
	 * Finds a mapping by the mapped WordPress user — the sole lookup
	 * ConversationDetailPage's attribution join and the deleted_user
	 * cleanup sequence both use.
	 *
	 * @param int $wp_user_id The mapped WordPress user.
	 *
	 * @return OperatorIdentity|null
	 */
	public function find_by_wp_user_id( int $wp_user_id ): ?OperatorIdentity {
		if ( ! $this->schema_health->is_available() ) {
			return null;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::OPERATOR_IDENTITIES_TABLE;
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE wp_user_id = %d", $wp_user_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return null === $row ? null : $this->hydrate( $row );
	}

	/**
	 * Finds a mapping by the mapped Telegram numeric sender id — the sole
	 * inbound-authorization lookup WebhookController performs (ADR-0026
	 * decision 2). SENSITIVE input; callers must never log it.
	 *
	 * @param int $telegram_user_id The Telegram numeric sender id.
	 *
	 * @return OperatorIdentity|null
	 */
	public function find_by_telegram_user_id( int $telegram_user_id ): ?OperatorIdentity {
		if ( ! $this->schema_health->is_available() ) {
			return null;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::OPERATOR_IDENTITIES_TABLE;
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE telegram_user_id = %d", $telegram_user_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return null === $row ? null : $this->hydrate( $row );
	}

	/**
	 * Every mapping, for the OperatorIdentityPage's own listing.
	 *
	 * @return array<int, OperatorIdentity>
	 */
	public function all(): array {
		if ( ! $this->schema_health->is_available() ) {
			return array();
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::OPERATOR_IDENTITIES_TABLE;
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_map( array( $this, 'hydrate' ), null === $rows ? array() : $rows );
	}

	/**
	 * Deletes a mapping by the mapped WordPress user — the final step of
	 * the deleted_user operator-cleanup sequence (ADR-0026 decision 12e),
	 * always called after the mapped Telegram id has already been used to
	 * clear message attribution.
	 *
	 * @param int $wp_user_id The mapped WordPress user.
	 *
	 * @return bool
	 */
	public function delete_for_wp_user( int $wp_user_id ): bool {
		if ( ! $this->schema_health->is_available() ) {
			return false;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::OPERATOR_IDENTITIES_TABLE;

		return false !== $wpdb->delete( $table, array( 'wp_user_id' => $wp_user_id ), array( '%d' ) );
	}

	/**
	 * Hydrates one database row into an OperatorIdentity.
	 *
	 * @param array<string, mixed> $row The raw database row.
	 *
	 * @return OperatorIdentity
	 */
	private function hydrate( array $row ): OperatorIdentity {
		return new OperatorIdentity(
			(int) $row['id'],
			(int) $row['wp_user_id'],
			(int) $row['telegram_user_id'],
			null === $row['telegram_username'] ? null : (string) $row['telegram_username'],
			(string) $row['created_at'],
			(int) $row['created_by']
		);
	}
}
