<?php
/**
 * Event type registry.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Events;

use UniversalTelegram\Privacy\Classification;

/**
 * Composition-root-owned, one instance per request, rebuilt fresh every
 * request — consistent with docs/adr/0007's "always constructed, checked at
 * point of use" pattern, never cached across requests. Populated entirely
 * by callbacks attached to the universal_telegram_register_event_types
 * hook (M02 plan §5.3).
 */
final class Registry {

	/**
	 * event_type => schema_version.
	 *
	 * @var array<string, int>
	 */
	private array $schema_versions = array();

	/**
	 * event_type => (dot-path => Classification).
	 *
	 * @var array<string, array<string, Classification>>
	 */
	private array $classification_maps = array();

	/**
	 * event_type => list of allowed variable-field dot-paths.
	 *
	 * @var array<string, array<int, string>>
	 */
	private array $allowed_variable_fields = array();

	/**
	 * event_type => list of PUBLIC-only history-projection dot-paths.
	 *
	 * @var array<string, array<int, string>>
	 */
	private array $history_projection_fields = array();

	/**
	 * Registers one event type. Fail-closed on three independent checks
	 * (M02 plan §5.2, docs/adr/0017).
	 *
	 * @param string                         $event_type                The namespaced, dot-separated event type.
	 * @param int                            $schema_version            The event type's schema version.
	 * @param array<string, Classification>  $field_classification_map  Dot-notation path to classification; every allowed field.
	 * @param array<int, string>             $allowed_variable_fields    Subset of the map's paths; usable in conditions/templates only.
	 * @param array<int, string>             $history_projection_fields  Subset of the map's paths; MUST be classified PUBLIC.
	 *
	 * @throws EventTypeAlreadyRegisteredException If (event_type, schema_version) was already registered.
	 * @throws UnclassifiedFieldException          If an allowed/history field is not a member of the classification map.
	 * @throws NonPublicHistoryFieldException      If a history-projection field is not classified PUBLIC.
	 */
	public function register(
		string $event_type,
		int $schema_version,
		array $field_classification_map,
		array $allowed_variable_fields,
		array $history_projection_fields
	): void {
		if ( isset( $this->schema_versions[ $event_type ] ) ) {
			throw new EventTypeAlreadyRegisteredException( sprintf( 'Event type "%s" is already registered.', $event_type ) );
		}

		foreach ( $allowed_variable_fields as $field ) {
			if ( ! isset( $field_classification_map[ $field ] ) ) {
				throw new UnclassifiedFieldException( sprintf( 'Allowed variable field "%s" is not present in the classification map.', $field ) );
			}
		}

		foreach ( $history_projection_fields as $field ) {
			if ( ! isset( $field_classification_map[ $field ] ) ) {
				throw new UnclassifiedFieldException( sprintf( 'History projection field "%s" is not present in the classification map.', $field ) );
			}

			if ( Classification::PUBLIC !== $field_classification_map[ $field ] ) {
				throw new NonPublicHistoryFieldException(
					sprintf( 'History projection field "%s" must be classified PUBLIC, not %s.', $field, $field_classification_map[ $field ]->value )
				);
			}
		}

		$this->schema_versions[ $event_type ]            = $schema_version;
		$this->classification_maps[ $event_type ]        = $field_classification_map;
		$this->allowed_variable_fields[ $event_type ]     = $allowed_variable_fields;
		$this->history_projection_fields[ $event_type ]   = $history_projection_fields;
	}

	/**
	 * Whether the given event type is registered.
	 *
	 * @param string $event_type The event type.
	 *
	 * @return bool
	 */
	public function is_registered( string $event_type ): bool {
		return isset( $this->schema_versions[ $event_type ] );
	}

	/**
	 * The registered schema version, or null if unregistered.
	 *
	 * @param string $event_type The event type.
	 *
	 * @return int|null
	 */
	public function schema_version_for( string $event_type ): ?int {
		return $this->schema_versions[ $event_type ] ?? null;
	}

	/**
	 * The registered classification map, or an empty array if unregistered.
	 *
	 * @param string $event_type The event type.
	 *
	 * @return array<string, Classification>
	 */
	public function classification_map_for( string $event_type ): array {
		return $this->classification_maps[ $event_type ] ?? array();
	}

	/**
	 * The registered allowed variable fields, or an empty array if
	 * unregistered.
	 *
	 * @param string $event_type The event type.
	 *
	 * @return array<int, string>
	 */
	public function allowed_variable_fields_for( string $event_type ): array {
		return $this->allowed_variable_fields[ $event_type ] ?? array();
	}

	/**
	 * The registered PUBLIC-only history-projection fields, or an empty
	 * array if unregistered.
	 *
	 * @param string $event_type The event type.
	 *
	 * @return array<int, string>
	 */
	public function history_projection_fields_for( string $event_type ): array {
		return $this->history_projection_fields[ $event_type ] ?? array();
	}

	/**
	 * Every registered event type's catalog entry, for the admin
	 * event-catalog browser (M02 plan §9.1).
	 *
	 * @return array<int, array{
	 *     event_type: string,
	 *     schema_version: int,
	 *     allowed_variable_fields: array<int, string>,
	 *     history_projection_fields: array<int, string>
	 * }>
	 */
	public function all(): array {
		$result = array();

		foreach ( $this->schema_versions as $event_type => $schema_version ) {
			$result[] = array(
				'event_type'                 => $event_type,
				'schema_version'              => $schema_version,
				'allowed_variable_fields'     => $this->allowed_variable_fields[ $event_type ] ?? array(),
				'history_projection_fields'   => $this->history_projection_fields[ $event_type ] ?? array(),
			);
		}

		return $result;
	}
}
