<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Administration\Automations;

use UniversalTelegram\Administration\Automations\RuleEditor;
use UniversalTelegram\Automations\NotificationRule;
use WP_UnitTestCase;

/**
 * A rule is representable only when every condition clause is both a
 * catalogued field (FieldTypeCatalog::has()) and one of that field's own
 * permitted friendly operators (M08.1 plan "Existing-rule compatibility
 * strategy"). Any single unmapped clause makes the whole rule's conditions
 * unrepresentable, preserved byte-for-byte via conditions_json.
 */
final class RuleEditorTest extends WP_UnitTestCase {

	private function rule( array $conditions, string $match_mode = 'all' ): NotificationRule {
		return new NotificationRule(
			1,
			'Test rule',
			'wordpress.user_registered',
			1,
			$conditions,
			$match_mode,
			2,
			3,
			'Hello {{subject.user_id}}',
			true,
			100,
			0,
			'now',
			'now'
		);
	}

	public function test_a_rule_with_no_conditions_is_representable(): void {
		$result = RuleEditor::from_existing( $this->rule( array() ) );

		$this->assertTrue( $result['representable'] );
		$this->assertSame( array(), $result['conditions'] );
	}

	public function test_a_rule_using_only_catalogued_fields_and_permitted_operators_is_representable(): void {
		$result = RuleEditor::from_existing(
			$this->rule(
				array(
					array(
						'field'    => 'subject.user_id',
						'operator' => 'equals',
						'value'    => '5',
					),
				)
			)
		);

		$this->assertTrue( $result['representable'] );
	}

	public function test_a_rule_referencing_an_uncatalogued_field_is_not_representable(): void {
		$result = RuleEditor::from_existing(
			$this->rule(
				array(
					array(
						'field'    => 'payload.this_field_is_not_catalogued',
						'operator' => 'equals',
						'value'    => 'x',
					),
				)
			)
		);

		$this->assertFalse( $result['representable'] );
		$this->assertStringContainsString( 'payload.this_field_is_not_catalogued', $result['conditions_json'] );
	}

	public function test_a_rule_using_an_operator_not_permitted_for_its_field_type_is_not_representable(): void {
		// 'subject.user_id' is numeric; 'contains' is not one of its
		// permitted operators (text-only).
		$result = RuleEditor::from_existing(
			$this->rule(
				array(
					array(
						'field'    => 'subject.user_id',
						'operator' => 'contains',
						'value'    => '5',
					),
				)
			)
		);

		$this->assertFalse( $result['representable'] );
	}

	public function test_one_unrepresentable_clause_marks_the_entire_rule_unrepresentable(): void {
		$result = RuleEditor::from_existing(
			$this->rule(
				array(
					array(
						'field'    => 'subject.user_id',
						'operator' => 'equals',
						'value'    => '5',
					),
					array(
						'field'    => 'payload.not_catalogued',
						'operator' => 'equals',
						'value'    => 'x',
					),
				)
			)
		);

		$this->assertFalse( $result['representable'] );
	}

	public function test_from_existing_carries_every_field_the_edit_form_needs(): void {
		$result = RuleEditor::from_existing( $this->rule( array() ) );

		$this->assertSame( 1, $result['id'] );
		$this->assertSame( 'Test rule', $result['name'] );
		$this->assertSame( 'wordpress.user_registered', $result['event_type'] );
		$this->assertSame( 'all', $result['match_mode'] );
		$this->assertSame( 2, $result['bot_id'] );
		$this->assertSame( 3, $result['destination_id'] );
		$this->assertSame( 'Hello {{subject.user_id}}', $result['template'] );
		$this->assertTrue( $result['enabled'] );
		$this->assertSame( 100, $result['priority'] );
		$this->assertSame( 0, $result['cooldown_seconds'] );
	}
}
