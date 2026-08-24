<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Administration\Automations;

use PHPUnit\Framework\TestCase;
use UniversalTelegram\Administration\Automations\FailingConditionExplainer;
use UniversalTelegram\Automations\ConditionClauseResult;
use UniversalTelegram\Automations\RuleMatchTrace;

/**
 * M08.2 plan §7 WP3: FailingConditionExplainer only formats an
 * already-computed RuleMatchTrace — every case here builds the trace
 * directly rather than evaluating a rule, proving no condition-comparison
 * logic is reimplemented in this class.
 */
final class FailingConditionExplainerTest extends TestCase {

	private function clause(
		string $field,
		string $operator,
		mixed $expected,
		mixed $actual,
		bool $field_present,
		bool $matched,
		bool $field_valid = true,
		bool $operator_valid = true
	): ConditionClauseResult {
		return new ConditionClauseResult( $field, $operator, $expected, $actual, $field_present, $matched, $field_valid, $operator_valid );
	}

	public function test_matched_trace_produces_no_sentences(): void {
		$trace = new RuleMatchTrace( true, 'all', array( $this->clause( 'subject.username', 'equals', 'jsmith', 'jsmith', true, true ) ) );

		$this->assertSame( array(), FailingConditionExplainer::explain( $trace ) );
	}

	public function test_absent_text_field_explains_absence_not_mismatch(): void {
		$trace = new RuleMatchTrace( false, 'all', array( $this->clause( 'subject.username', 'equals', 'jsmith', null, false, false ) ) );

		$sentences = FailingConditionExplainer::explain( $trace );

		$this->assertCount( 1, $sentences );
		$this->assertStringContainsString( 'Username', $sentences[0] );
		$this->assertStringContainsString( 'no value for it', $sentences[0] );
	}

	public function test_present_non_matching_text_field(): void {
		$trace = new RuleMatchTrace( false, 'all', array( $this->clause( 'subject.username', 'equals', 'jsmith', 'other-user', true, false ) ) );

		$sentences = FailingConditionExplainer::explain( $trace );

		$this->assertStringContainsString( 'Username', $sentences[0] );
		$this->assertStringContainsString( 'other-user', $sentences[0] );
		$this->assertStringContainsString( 'jsmith', $sentences[0] );
		$this->assertStringContainsString( 'is', $sentences[0] );
	}

	public function test_present_non_matching_number_field(): void {
		$trace = new RuleMatchTrace( false, 'all', array( $this->clause( 'actor.user_id', 'equals', '42', '7', true, false ) ) );

		$sentences = FailingConditionExplainer::explain( $trace );

		$this->assertStringContainsString( 'User account ID', $sentences[0] );
		$this->assertStringContainsString( '7', $sentences[0] );
		$this->assertStringContainsString( '42', $sentences[0] );
	}

	public function test_absent_money_field(): void {
		$trace = new RuleMatchTrace( false, 'all', array( $this->clause( 'payload.order_total', 'at_least', '49.90', null, false, false ) ) );

		$sentences = FailingConditionExplainer::explain( $trace );

		$this->assertStringContainsString( 'no value for it', $sentences[0] );
	}

	public function test_present_non_matching_boolean_field_uses_friendly_yes_no(): void {
		$trace = new RuleMatchTrace( false, 'all', array( $this->clause( 'payload.network_wide', 'equals', 'true', 'false', true, false ) ) );

		$sentences = FailingConditionExplainer::explain( $trace );

		$this->assertStringContainsString( 'no', $sentences[0] );
		$this->assertStringContainsString( 'yes', $sentences[0] );
		$this->assertStringNotContainsString( 'false', $sentences[0] );
	}

	public function test_present_non_matching_choice_field_uses_friendly_option_label(): void {
		$trace = new RuleMatchTrace( false, 'all', array( $this->clause( 'payload.new_role', 'equals', 'administrator', 'subscriber', true, false ) ) );

		$sentences = FailingConditionExplainer::explain( $trace );

		$this->assertStringContainsString( 'Subscriber', $sentences[0] );
		$this->assertStringContainsString( 'Administrator', $sentences[0] );
	}

	public function test_any_mode_none_matched_explains_every_clause(): void {
		$trace = new RuleMatchTrace(
			false,
			'any',
			array(
				$this->clause( 'subject.username', 'equals', 'jsmith', 'other-user', true, false ),
				$this->clause( 'actor.user_id', 'equals', '42', null, false, false ),
			)
		);

		$sentences = FailingConditionExplainer::explain( $trace );

		$this->assertCount( 2, $sentences );
	}

	public function test_invalid_field_or_operator_clause_is_excluded_from_prose(): void {
		$trace = new RuleMatchTrace(
			false,
			'all',
			array(
				$this->clause( 'payload.does_not_exist', 'equals', 'x', null, false, false, false, true ),
				$this->clause( 'subject.username', 'not_a_real_operator', 'jsmith', 'jsmith', true, false, true, false ),
			)
		);

		$this->assertSame( array(), FailingConditionExplainer::explain( $trace ) );
	}

	public function test_a_mix_of_invalid_and_valid_clauses_explains_only_the_valid_one(): void {
		$trace = new RuleMatchTrace(
			false,
			'all',
			array(
				$this->clause( 'payload.does_not_exist', 'equals', 'x', null, false, false, false, true ),
				$this->clause( 'subject.username', 'equals', 'jsmith', 'other-user', true, false ),
			)
		);

		$sentences = FailingConditionExplainer::explain( $trace );

		$this->assertCount( 1, $sentences );
		$this->assertStringContainsString( 'Username', $sentences[0] );
	}
}
