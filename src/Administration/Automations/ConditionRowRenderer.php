<?php
/**
 * One "Only when…" condition row's field/operator/value controls.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Administration\Automations;

use UniversalTelegram\Events\Registry;

/**
 * Pure rendering, no state of its own. Every field offered is the
 * intersection of the selected event type's own
 * Registry::allowed_variable_fields_for() and FieldTypeCatalog's
 * fail-closed metadata (M08.1 plan "Field type metadata") — an
 * engine-allowed field this catalog hasn't covered simply never appears
 * here. Client-side JS (enqueued by RuleBuilderPage) re-populates the
 * operator `<select>` and swaps the value input's type when the field
 * changes, driven by the JSON metadata RuleBuilderPage embeds via
 * field_metadata_for_event(); this class only renders the initial,
 * server-known state, matching NotificationRuleRepository::save()'s own
 * authoritative field allowlist check either way.
 */
final class ConditionRowRenderer {

	/**
	 * Friendly labels for the fixed ConditionOperator grammar (M08.1 plan
	 * "Operator matrix").
	 *
	 * @var array<string, string>
	 */
	private const OPERATOR_LABELS = array(
		'equals'       => 'is',
		'not_equals'   => 'is not',
		'contains'     => 'contains',
		'not_contains' => 'does not contain',
		'greater_than' => 'greater than',
		'less_than'    => 'less than',
		'at_least'     => 'at least',
		'at_most'      => 'at most',
	);

	/**
	 * Friendly labels for boolean values.
	 *
	 * @var array<string, string>
	 */
	private const BOOLEAN_VALUE_LABELS = array(
		'true'  => 'yes',
		'false' => 'no',
	);

	/**
	 * The fields eligible for the condition builder and field-insert menu
	 * for one event type: allowed by the engine AND fully catalogued.
	 *
	 * @param string   $event_type The selected event type.
	 * @param Registry $registry   The current request's event registry.
	 *
	 * @return array<int, string> Field paths, in the engine's own registration order.
	 */
	public static function eligible_fields( string $event_type, Registry $registry ): array {
		return array_values(
			array_filter(
				$registry->allowed_variable_fields_for( $event_type ),
				static fn( string $field ): bool => FieldTypeCatalog::has( $field )
			)
		);
	}

	/**
	 * Renders one condition row.
	 *
	 * @param int                  $index      The row's zero-based index within conditions[].
	 * @param string               $event_type The selected event type.
	 * @param Registry             $registry   The current request's event registry.
	 * @param array<string, mixed> $clause     The current field/operator/value, or empty for a blank row.
	 */
	public static function render( int $index, string $event_type, Registry $registry, array $clause = array() ): void {
		$fields          = self::eligible_fields( $event_type, $registry );
		$selected_field  = isset( $clause['field'] ) ? (string) $clause['field'] : ( $fields[0] ?? '' );
		$selected_op     = isset( $clause['operator'] ) ? (string) $clause['operator'] : '';
		$selected_value  = isset( $clause['value'] ) ? (string) $clause['value'] : '';

		echo '<div class="ut-condition-row" data-index="' . esc_attr( (string) $index ) . '">';

		echo '<select class="ut-condition-field" name="conditions[' . esc_attr( (string) $index ) . '][field]">';
		foreach ( $fields as $field ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $field ),
				selected( $selected_field, $field, false ),
				esc_html( (string) FieldTypeCatalog::label( $field ) )
			);
		}
		echo '</select> ';

		echo '<select class="ut-condition-operator" name="conditions[' . esc_attr( (string) $index ) . '][operator]">';
		foreach ( FieldTypeCatalog::operators( $selected_field ) as $operator ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $operator ),
				selected( $selected_op, $operator, false ),
				esc_html( self::OPERATOR_LABELS[ $operator ] ?? $operator )
			);
		}
		echo '</select> ';

		self::render_value_input( $index, $selected_field, $selected_value );

		echo ' <button type="button" class="button ut-remove-condition">' . esc_html__( 'Remove', 'universal-telegram' ) . '</button>';
		echo '</div>';
	}

	/**
	 * Renders the value control for the field's own type — a fixed
	 * `<select>` for choice/boolean, a plain `<input>` otherwise.
	 *
	 * @param int    $index          The row's index.
	 * @param string $field          The selected field path.
	 * @param string $selected_value The current value.
	 */
	private static function render_value_input( int $index, string $field, string $selected_value ): void {
		$type = FieldTypeCatalog::type( $field );
		$name = 'conditions[' . $index . '][value]';

		if ( FieldTypeCatalog::TYPE_CHOICE === $type ) {
			echo '<select class="ut-condition-value" name="' . esc_attr( $name ) . '">';
			foreach ( FieldTypeCatalog::choice_options( $field ) as $value => $label ) {
				printf(
					'<option value="%s" %s>%s</option>',
					esc_attr( $value ),
					selected( $selected_value, $value, false ),
					esc_html( $label )
				);
			}
			echo '</select>';
			return;
		}

		if ( FieldTypeCatalog::TYPE_BOOLEAN === $type ) {
			echo '<select class="ut-condition-value" name="' . esc_attr( $name ) . '">';
			foreach ( self::BOOLEAN_VALUE_LABELS as $value => $label ) {
				printf(
					'<option value="%s" %s>%s</option>',
					esc_attr( $value ),
					selected( $selected_value, $value, false ),
					esc_html( $label )
				);
			}
			echo '</select>';
			return;
		}

		$input_type = in_array( $type, array( FieldTypeCatalog::TYPE_NUMBER, FieldTypeCatalog::TYPE_MONEY ), true ) ? 'number' : 'text';
		$step       = FieldTypeCatalog::TYPE_MONEY === $type ? ' step="0.01"' : '';

		printf(
			'<input type="%s" class="ut-condition-value regular-text" name="%s" value="%s"%s />',
			esc_attr( $input_type ),
			esc_attr( $name ),
			esc_attr( $selected_value ),
			$step // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed literal string, not user input.
		);
	}

	/**
	 * The client-side condition-field metadata for one event type, embedded
	 * as JSON by RuleBuilderPage so the row-add/field-change JS can rebuild
	 * operator/value controls without a page reload. Contains only friendly
	 * labels and fixed operator/choice metadata — never anything from a
	 * real event occurrence.
	 *
	 * @param string   $event_type The event type.
	 * @param Registry $registry   The current request's event registry.
	 *
	 * @return array<string, array{label: string, type: string, operators: array<int, array{value: string, label: string}>, choice_options?: array<int, array{value: string, label: string}>}>
	 */
	public static function field_metadata_for_event( string $event_type, Registry $registry ): array {
		$metadata = array();

		foreach ( self::eligible_fields( $event_type, $registry ) as $field ) {
			$type      = (string) FieldTypeCatalog::type( $field );
			$operators = array();

			foreach ( FieldTypeCatalog::operators( $field ) as $operator ) {
				$operators[] = array(
					'value' => $operator,
					'label' => self::OPERATOR_LABELS[ $operator ] ?? $operator,
				);
			}

			$entry = array(
				'label'     => (string) FieldTypeCatalog::label( $field ),
				'type'      => $type,
				'operators' => $operators,
			);

			if ( FieldTypeCatalog::TYPE_CHOICE === $type ) {
				$choice_options = array();
				foreach ( FieldTypeCatalog::choice_options( $field ) as $value => $label ) {
					$choice_options[] = array(
						'value' => $value,
						'label' => $label,
					);
				}
				$entry['choice_options'] = $choice_options;
			} elseif ( FieldTypeCatalog::TYPE_BOOLEAN === $type ) {
				$choice_options = array();
				foreach ( self::BOOLEAN_VALUE_LABELS as $value => $label ) {
					$choice_options[] = array(
						'value' => $value,
						'label' => $label,
					);
				}
				$entry['choice_options'] = $choice_options;
			}

			$metadata[ $field ] = $entry;
		}

		return $metadata;
	}

	/**
	 * The friendly operator label map, exposed for reuse by RuleEditor's
	 * legacy-rule rendering (WP6).
	 *
	 * @return array<string, string>
	 */
	public static function operator_labels(): array {
		return self::OPERATOR_LABELS;
	}
}
