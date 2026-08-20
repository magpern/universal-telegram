<?php
/**
 * Notification rule persistence.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Automations;

use UniversalTelegram\Events\Registry;
use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Persistence\SchemaHealth;

/**
 * CRUD for notification rules. Checks SchemaHealth::is_available() at its
 * own point of use (docs/adr/0007). save() is the authoritative,
 * server-side validation of a condition clause's field against the rule's
 * own event type's allowlist (M02 plan §7.2) — a client-side check is never
 * trusted alone. for_event_type()'s own ORDER BY is the mechanism that
 * makes rule evaluation deterministic (M02 plan §7.3).
 */
final class NotificationRuleRepository {

	/**
	 * Constructor.
	 *
	 * @param SchemaHealth $schema_health Checked before every operation.
	 * @param Registry     $registry      Supplies each event type's allowed variable fields.
	 */
	public function __construct(
		private readonly SchemaHealth $schema_health,
		private readonly Registry $registry
	) {}

	/**
	 * Creates or updates a rule. Validates every condition clause's field
	 * against the event type's own allowlist before writing anything.
	 *
	 * @param int|null                                                            $id                 Null to create; an existing ID to update.
	 * @param string                                                              $name               Admin-facing name.
	 * @param string                                                              $event_type         The triggering event type.
	 * @param int                                                                 $schema_version_min The minimum schema version this rule applies to.
	 * @param array<int, array{field: string, operator: string, value: mixed}>    $conditions         Flat AND-only clause array.
	 * @param int                                                                 $bot_id             The Telegram bot to send through.
	 * @param int                                                                 $destination_id     The Telegram destination to send to.
	 * @param string                                                              $template           The message template.
	 * @param bool                                                                $enabled            Whether this rule is currently evaluated.
	 * @param int                                                                 $priority           Deterministic evaluation ordering (ascending).
	 * @param int                                                                 $cooldown_seconds   Minimum seconds between successful dispatches.
	 *
	 * @return NotificationRule|null Null if the schema is unavailable or the write failed.
	 *
	 * @throws InvalidConditionFieldException If any condition clause's field is not allowed for this event type.
	 */
	public function save(
		?int $id,
		string $name,
		string $event_type,
		int $schema_version_min,
		array $conditions,
		int $bot_id,
		int $destination_id,
		string $template,
		bool $enabled,
		int $priority,
		int $cooldown_seconds
	): ?NotificationRule {
		$allowed_fields = $this->registry->allowed_variable_fields_for( $event_type );

		foreach ( $conditions as $clause ) {
			$field = $clause['field'] ?? null;

			if ( ! is_string( $field ) || ! in_array( $field, $allowed_fields, true ) ) {
				throw new InvalidConditionFieldException(
					sprintf( 'Condition field "%s" is not allowed for event type "%s".', is_string( $field ) ? $field : '(missing)', $event_type )
				);
			}
		}

		if ( ! $this->schema_health->is_available() ) {
			return null;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::NOTIFICATION_RULES_TABLE;
		$now   = current_time( 'mysql', true );

		$row = array(
			'name'                => $name,
			'event_type'          => $event_type,
			'schema_version_min'  => $schema_version_min,
			'conditions_json'     => wp_json_encode( array_values( $conditions ) ),
			'bot_id'              => $bot_id,
			'destination_id'      => $destination_id,
			'template'            => $template,
			'enabled'             => $enabled ? 1 : 0,
			'priority'            => $priority,
			'cooldown_seconds'    => $cooldown_seconds,
			'updated_at'          => $now,
		);
		$formats = array( '%s', '%s', '%d', '%s', '%d', '%d', '%s', '%d', '%d', '%d', '%s' );

		if ( null === $id ) {
			$row['created_at'] = $now;
			$formats[]         = '%s';

			$inserted = $wpdb->insert( $table, $row, $formats );

			if ( false === $inserted ) {
				return null;
			}

			return $this->find( (int) $wpdb->insert_id );
		}

		$updated = $wpdb->update( $table, $row, array( 'id' => $id ), $formats, array( '%d' ) );

		if ( false === $updated ) {
			return null;
		}

		return $this->find( $id );
	}

	/**
	 * Finds a rule by primary key.
	 *
	 * @param int $id The rule's primary key.
	 *
	 * @return NotificationRule|null
	 */
	public function find( int $id ): ?NotificationRule {
		if ( ! $this->schema_health->is_available() ) {
			return null;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::NOTIFICATION_RULES_TABLE;
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return null === $row ? null : $this->hydrate( $row );
	}

	/**
	 * Every rule registered for an event type, in the exact deterministic
	 * order rule evaluation uses: priority ASC, id ASC.
	 *
	 * @param string $event_type   The event type.
	 * @param bool   $enabled_only Whether to restrict to enabled rules.
	 *
	 * @return array<int, NotificationRule>
	 */
	public function for_event_type( string $event_type, bool $enabled_only = true ): array {
		if ( ! $this->schema_health->is_available() ) {
			return array();
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::NOTIFICATION_RULES_TABLE;

		if ( $enabled_only ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE event_type = %s AND enabled = 1 ORDER BY priority ASC, id ASC", $event_type ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE event_type = %s ORDER BY priority ASC, id ASC", $event_type ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				ARRAY_A
			);
		}

		return array_map( array( $this, 'hydrate' ), null === $rows ? array() : $rows );
	}

	/**
	 * Every rule, regardless of event type or enabled state, for the admin
	 * rule-builder list.
	 *
	 * @return array<int, NotificationRule>
	 */
	public function all(): array {
		if ( ! $this->schema_health->is_available() ) {
			return array();
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::NOTIFICATION_RULES_TABLE;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY event_type ASC, priority ASC, id ASC", ARRAY_A );

		return array_map( array( $this, 'hydrate' ), null === $rows ? array() : $rows );
	}

	/**
	 * The total number of rules, regardless of event type or enabled
	 * state. Used only for diagnostics aggregation.
	 *
	 * @return int
	 */
	public function count_all(): int {
		if ( ! $this->schema_health->is_available() ) {
			return 0;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::NOTIFICATION_RULES_TABLE;

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * The total number of enabled rules. Used only for diagnostics
	 * aggregation.
	 *
	 * @return int
	 */
	public function count_enabled(): int {
		if ( ! $this->schema_health->is_available() ) {
			return 0;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::NOTIFICATION_RULES_TABLE;

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE enabled = 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Deletes a rule.
	 *
	 * @param int $id The rule's primary key.
	 *
	 * @return bool
	 */
	public function delete( int $id ): bool {
		if ( ! $this->schema_health->is_available() ) {
			return false;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::NOTIFICATION_RULES_TABLE;

		return false !== $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Hydrates one database row into a NotificationRule.
	 *
	 * @param array<string, mixed> $row The raw database row.
	 *
	 * @return NotificationRule
	 */
	private function hydrate( array $row ): NotificationRule {
		$conditions = json_decode( (string) $row['conditions_json'], true );

		return new NotificationRule(
			(int) $row['id'],
			(string) $row['name'],
			(string) $row['event_type'],
			(int) $row['schema_version_min'],
			is_array( $conditions ) ? $conditions : array(),
			(int) $row['bot_id'],
			(int) $row['destination_id'],
			(string) $row['template'],
			'1' === (string) $row['enabled'] || 1 === (int) $row['enabled'],
			(int) $row['priority'],
			(int) $row['cooldown_seconds'],
			(string) $row['created_at'],
			(string) $row['updated_at']
		);
	}
}
