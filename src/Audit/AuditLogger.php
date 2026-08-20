<?php
/**
 * Audit log writer.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Audit;

use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Privacy\Classification;
use UniversalTelegram\Privacy\Redactor;

/**
 * Sole writer of the plugin's one database table. Requires a
 * classification map on every call and performs redaction internally,
 * itself, before persisting anything — redaction can never be skipped by
 * a caller that forgets to pre-redact its own data (docs/adr/0009).
 */
final class AuditLogger {

	/**
	 * Checked before every write.
	 *
	 * @var SchemaHealth
	 */
	private SchemaHealth $schema_health;

	/**
	 * Applied to context before every write.
	 *
	 * @var Redactor
	 */
	private Redactor $redactor;

	/**
	 * Constructor.
	 *
	 * @param SchemaHealth $schema_health Checked before every write.
	 * @param Redactor     $redactor      Applied to context before every write.
	 */
	public function __construct( SchemaHealth $schema_health, Redactor $redactor ) {
		$this->schema_health = $schema_health;
		$this->redactor      = $redactor;
	}

	/**
	 * Records one audit entry. Never throws; returns whether the write
	 * succeeded so a caller (e.g. the queue's own failure-handling
	 * sequence) can fall back to its own stable-code-only logger.
	 *
	 * @param string                        $action              What happened.
	 * @param string                        $actor_type          The kind of actor.
	 * @param int|null                      $actor_id            The actor's ID, if any.
	 * @param array<string, mixed>          $context             Raw context, redacted internally.
	 * @param array<string, Classification> $classification_map  Dot-notation path to classification.
	 * @param Classification                $overall_classification The entry's own overall sensitivity.
	 *
	 * @return bool
	 */
	public function record(
		string $action,
		string $actor_type,
		?int $actor_id,
		array $context,
		array $classification_map,
		Classification $overall_classification
	): bool {
		if ( ! $this->schema_health->is_available() ) {
			return false;
		}

		global $wpdb;

		$redacted = $this->redactor->redact( $context, $classification_map );
		$table    = $wpdb->prefix . Migrator::AUDIT_LOG_TABLE;

		$inserted = $wpdb->insert(
			$table,
			array(
				'occurred_at'            => current_time( 'mysql', true ),
				'actor_type'             => $actor_type,
				'actor_id'               => $actor_id,
				'action'                 => $action,
				'context'                => wp_json_encode( $redacted ),
				'privacy_classification' => $overall_classification->value,
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		return false !== $inserted;
	}
}
