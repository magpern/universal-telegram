<?php
/**
 * Legacy-rule-to-friendly-builder translation (M08.1).
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Administration\Automations;

use UniversalTelegram\Automations\NotificationRule;

/**
 * Renders an existing NotificationRule through the friendly builder where
 * every one of its condition clauses is representable — a catalogued field
 * (FieldTypeCatalog::has()) with one of that field's own permitted friendly
 * operators. If any clause is not representable, the entire condition set
 * is treated as unrepresentable and preserved byte-for-byte: the caller
 * renders a read-only compatibility notice instead of condition rows, and
 * resubmits the exact original conditions_json/match_mode via hidden
 * fields rather than the editable builder (M08.1 plan "Existing-rule
 * compatibility strategy"). This class performs no validation of its
 * own — NotificationRuleRepository::save() remains the sole authority.
 */
final class RuleEditor {

	/**
	 * Translates one rule into the shape the Add Rule form's edit mode
	 * needs.
	 *
	 * @param NotificationRule $rule The rule being edited.
	 *
	 * @return array{id: int, name: string, event_type: string, representable: bool, conditions: array<int, array<string, mixed>>, match_mode: string, conditions_json: string, bot_id: int, destination_id: int, template: string, enabled: bool, priority: int, cooldown_seconds: int}
	 */
	public static function from_existing( NotificationRule $rule ): array {
		return array(
			'id'               => $rule->id(),
			'name'             => $rule->name(),
			'event_type'       => $rule->event_type(),
			'representable'    => self::is_representable( $rule ),
			'conditions'       => $rule->conditions(),
			'match_mode'       => $rule->match_mode(),
			'conditions_json'  => (string) wp_json_encode( $rule->conditions() ),
			'bot_id'           => $rule->bot_id(),
			'destination_id'   => $rule->destination_id(),
			'template'         => $rule->template(),
			'enabled'          => $rule->enabled(),
			'priority'         => $rule->priority(),
			'cooldown_seconds' => $rule->cooldown_seconds(),
		);
	}

	/**
	 * Whether every condition clause of this rule is representable by the
	 * visual builder: a catalogued field with one of its own permitted
	 * friendly operators.
	 *
	 * @param NotificationRule $rule The rule to check.
	 *
	 * @return bool
	 */
	private static function is_representable( NotificationRule $rule ): bool {
		foreach ( $rule->conditions() as $clause ) {
			$field    = $clause['field'] ?? null;
			$operator = $clause['operator'] ?? null;

			if ( ! is_string( $field ) || ! FieldTypeCatalog::has( $field ) ) {
				return false;
			}

			if ( ! is_string( $operator ) || ! in_array( $operator, FieldTypeCatalog::operators( $field ), true ) ) {
				return false;
			}
		}

		return true;
	}
}
