<?php
/**
 * Audit log reader.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Audit;

use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Persistence\SchemaHealth;

/**
 * Read path for the plugin's one database table.
 */
final class AuditLogRepository {

	/**
	 * Checked before every read.
	 *
	 * @var SchemaHealth
	 */
	private SchemaHealth $schema_health;

	/**
	 * Constructor.
	 *
	 * @param SchemaHealth $schema_health Checked before every read.
	 */
	public function __construct( SchemaHealth $schema_health ) {
		$this->schema_health = $schema_health;
	}

	/**
	 * The most recent entries, newest first.
	 *
	 * @param int $limit Maximum number of entries to return.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function recent( int $limit = 20 ): array {
		if ( ! $this->schema_health->is_available() ) {
			return array();
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::AUDIT_LOG_TABLE;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name, never user input.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, occurred_at, actor_type, actor_id, action, context, privacy_classification FROM {$table} ORDER BY occurred_at DESC LIMIT %d",
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_array( $rows ) ? $rows : array();
	}
}
