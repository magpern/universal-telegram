<?php
/**
 * Outbound delivery idempotency keys for Support Chat adapter accepts.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\SupportChatAdapter;

use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Persistence\SchemaHealth;

/**
 * Deduplicates deliver_message / backfill accepts on the Contract
 * idempotency key before enqueueing the outbound pipeline.
 */
final class DeliveryIdempotencyRepository {

	/**
	 * Constructor.
	 *
	 * @param SchemaHealth $schema_health Checked before every operation.
	 */
	public function __construct( private readonly SchemaHealth $schema_health ) {}

	/**
	 * Returns an existing accept row for the key, if any.
	 *
	 * @param string $idempotency_key Contract idempotency key.
	 *
	 * @return array{outbound_message_uuid: ?string}|null
	 */
	public function find( string $idempotency_key ): ?array {
		if ( ! $this->schema_health->is_available() ) {
			return null;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::SUPPORT_CHAT_DELIVERY_KEYS_TABLE;
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT outbound_message_uuid FROM {$table} WHERE idempotency_key = %s", $idempotency_key ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		if ( null === $row ) {
			return null;
		}

		$uuid = $row['outbound_message_uuid'] ?? null;

		return array(
			'outbound_message_uuid' => is_string( $uuid ) && '' !== $uuid ? $uuid : null,
		);
	}

	/**
	 * Records a newly accepted delivery key.
	 *
	 * @param string      $idempotency_key       Contract key.
	 * @param string      $binding_uuid          Binding UUID.
	 * @param string|null $outbound_message_uuid Outbound queue UUID when known.
	 */
	public function record( string $idempotency_key, string $binding_uuid, ?string $outbound_message_uuid ): bool {
		if ( ! $this->schema_health->is_available() ) {
			return false;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::SUPPORT_CHAT_DELIVERY_KEYS_TABLE;
		$ok    = $wpdb->insert(
			$table,
			array(
				'idempotency_key'       => $idempotency_key,
				'binding_uuid'          => $binding_uuid,
				'outbound_message_uuid' => $outbound_message_uuid,
				'created_at'            => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s' )
		);

		return false !== $ok;
	}
}
