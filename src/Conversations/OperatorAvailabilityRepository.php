<?php
/**
 * Operator availability persistence.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Conversations;

use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Persistence\SchemaHealth;

/**
 * CRUD for operator three-state availability (M07, docs/adr/0026). An
 * operator self-sets their own state under MANAGE_CONVERSATIONS; an
 * administrator (MANAGE) may set another mapped operator's state — this
 * repository itself enforces no capability, only the calling handler does.
 */
class OperatorAvailabilityRepository {

	/**
	 * Constructor.
	 *
	 * @param SchemaHealth $schema_health Checked before every operation.
	 */
	public function __construct(
		private readonly SchemaHealth $schema_health
	) {}

	/**
	 * Finds an operator's current availability row.
	 *
	 * @param int $operator_user_id The operator.
	 *
	 * @return OperatorAvailability|null
	 */
	public function find_for_operator( int $operator_user_id ): ?OperatorAvailability {
		if ( ! $this->schema_health->is_available() ) {
			return null;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::OPERATOR_AVAILABILITY_TABLE;
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE operator_user_id = %d", $operator_user_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return null === $row ? null : $this->hydrate( $row );
	}

	/**
	 * Sets an operator's availability state, creating the row on first use.
	 *
	 * @param int    $operator_user_id The operator whose state is being set.
	 * @param string $state            One of OperatorAvailability::AVAILABLE|BUSY|OFFLINE.
	 * @param int    $updated_by       The WordPress user performing this change (the operator themself, or a MANAGE administrator).
	 *
	 * @return OperatorAvailability|null
	 */
	public function set_state( int $operator_user_id, string $state, int $updated_by ): ?OperatorAvailability {
		if ( ! $this->schema_health->is_available() ) {
			return null;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::OPERATOR_AVAILABILITY_TABLE;
		$now   = current_time( 'mysql', true );

		$existing = $this->find_for_operator( $operator_user_id );

		if ( null === $existing ) {
			$wpdb->insert(
				$table,
				array(
					'operator_user_id' => $operator_user_id,
					'state'            => $state,
					'updated_at'       => $now,
					'updated_by'       => $updated_by,
				),
				array( '%d', '%s', '%s', '%d' )
			);
		} else {
			$wpdb->update(
				$table,
				array(
					'state'      => $state,
					'updated_at' => $now,
					'updated_by' => $updated_by,
				),
				array( 'operator_user_id' => $operator_user_id ),
				array( '%s', '%s', '%d' ),
				array( '%d' )
			);
		}

		return $this->find_for_operator( $operator_user_id );
	}

	/**
	 * Deletes an operator's availability row — part of the
	 * deleted_user operator-cleanup sequence (ADR-0026 decision 12d).
	 *
	 * @param int $operator_user_id The operator whose availability row is deleted.
	 *
	 * @return bool
	 */
	public function delete_for_operator( int $operator_user_id ): bool {
		if ( ! $this->schema_health->is_available() ) {
			return false;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::OPERATOR_AVAILABILITY_TABLE;

		return false !== $wpdb->delete( $table, array( 'operator_user_id' => $operator_user_id ), array( '%d' ) );
	}

	/**
	 * Hydrates one database row into an OperatorAvailability.
	 *
	 * @param array<string, mixed> $row The raw database row.
	 *
	 * @return OperatorAvailability
	 */
	private function hydrate( array $row ): OperatorAvailability {
		return new OperatorAvailability(
			(int) $row['id'],
			(int) $row['operator_user_id'],
			(string) $row['state'],
			(string) $row['updated_at'],
			(int) $row['updated_by']
		);
	}
}
