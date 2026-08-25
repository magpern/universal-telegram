<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Administration\Automations;

use UniversalTelegram\Administration\Automations\NotificationTester;
use UniversalTelegram\Administration\Automations\NotificationTestOutcome;
use UniversalTelegram\Administration\Automations\PreviewRenderer;
use UniversalTelegram\Automations\DispatchLogRepository;
use UniversalTelegram\Automations\NotificationDispatcher;
use UniversalTelegram\Automations\NotificationRule;
use UniversalTelegram\Automations\NotificationRuleRepository;
use UniversalTelegram\Automations\RuleEvaluator;
use UniversalTelegram\Core\Plugin;
use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Telegram\Configuration\BotProfile;
use UniversalTelegram\Telegram\Configuration\BotStatus;
use UniversalTelegram\Telegram\Configuration\Destination;
use UniversalTelegram\Telegram\Configuration\DestinationKind;
use WP_UnitTestCase;

/**
 * NotificationTester (M08.2 plan §7 WP4): proves every outcome in plan §8,
 * and that no scenario writes to notification_dispatch_log, event_history,
 * or the audit log — the runtime half of the no-side-effect contract
 * (§6), complementing NotificationTesterStructuralTest's own
 * constructor-allowlist proof.
 */
final class NotificationTesterTest extends WP_UnitTestCase {

	private const EVENT_TYPE = 'wordpress.user_registered';

	private function rules(): NotificationRuleRepository {
		return new NotificationRuleRepository( new SchemaHealth(), Plugin::instance()->event_registry() );
	}

	private function tester( NotificationRuleRepository $rules ): NotificationTester {
		$registry     = Plugin::instance()->event_registry();
		$dispatch_log = $this->createMock( DispatchLogRepository::class );
		$dispatcher   = $this->createMock( NotificationDispatcher::class );
		$dispatcher->expects( $this->never() )->method( 'dispatch' );

		$evaluator = new RuleEvaluator( $rules, $registry, $dispatch_log, $dispatcher );

		return new NotificationTester(
			$evaluator,
			$rules,
			Plugin::instance()->bot_profile_repository(),
			Plugin::instance()->destination_repository(),
			$registry,
			new PreviewRenderer( $registry )
		);
	}

	/**
	 * @return array{0: BotProfile, 1: Destination}
	 */
	private function eligible_bot_and_destination(): array {
		$bot = Plugin::instance()->bot_profile_repository()->create( 'Bot', str_repeat( 'a', 46 ) );
		$this->assertNotNull( $bot );
		Plugin::instance()->bot_profile_repository()->set_status( $bot->id(), BotStatus::ACTIVE );

		$destination = Plugin::instance()->destination_repository()->create( $bot->id(), DestinationKind::GROUP, '-100123', null, 'Ops' );
		$this->assertNotNull( $destination );

		return array( $bot, $destination );
	}

	private function save_rule(
		NotificationRuleRepository $rules,
		int $bot_id,
		int $destination_id,
		array $conditions = array(),
		string $match_mode = 'all',
		bool $enabled = true,
		string $template = 'Welcome {{subject.username}}'
	): NotificationRule {
		$rule = $rules->save( null, 'Test rule', self::EVENT_TYPE, 1, $conditions, $bot_id, $destination_id, $template, $enabled, 100, 0, $match_mode );
		$this->assertNotNull( $rule );

		return $rule;
	}

	public function test_all_mode_rule_matching_every_clause_would_send_with_a_preview(): void {
		[ $bot, $destination ] = $this->eligible_bot_and_destination();
		$rules                 = $this->rules();
		$rule                  = $this->save_rule(
			$rules,
			$bot->id(),
			$destination->id(),
			array(
				array(
					'field'    => 'subject.username',
					'operator' => 'equals',
					'value'    => 'jsmith',
				),
			)
		);

		$result = $this->tester( $rules )->test_rule( $rule, array( 'subject.username' => 'jsmith' ) );

		$this->assertSame( NotificationTestOutcome::WOULD_SEND, $result->outcome() );
		$this->assertNotNull( $result->rendered_preview() );
		$this->assertNotSame( '', $result->rendered_preview() );
	}

	public function test_all_mode_rule_with_one_broken_clause_is_not_matched_with_a_reason(): void {
		[ $bot, $destination ] = $this->eligible_bot_and_destination();
		$rules                 = $this->rules();
		$rule                  = $this->save_rule(
			$rules,
			$bot->id(),
			$destination->id(),
			array(
				array(
					'field'    => 'subject.username',
					'operator' => 'equals',
					'value'    => 'jsmith',
				),
			)
		);

		$result = $this->tester( $rules )->test_rule( $rule, array( 'subject.username' => 'someone-else' ) );

		$this->assertSame( NotificationTestOutcome::NOT_MATCHED, $result->outcome() );
		$this->assertCount( 1, $result->failing_reasons() );
		$this->assertNull( $result->rendered_preview() );
	}

	public function test_any_mode_matches_when_one_of_two_clauses_matches(): void {
		[ $bot, $destination ] = $this->eligible_bot_and_destination();
		$rules                 = $this->rules();
		$rule                  = $this->save_rule(
			$rules,
			$bot->id(),
			$destination->id(),
			array(
				array(
					'field'    => 'subject.username',
					'operator' => 'equals',
					'value'    => 'jsmith',
				),
				array(
					'field'    => 'subject.email',
					'operator' => 'equals',
					'value'    => 'jane@example.com',
				),
			),
			'any'
		);

		$result = $this->tester( $rules )->test_rule(
			$rule,
			array(
				'subject.username' => 'jsmith',
				'subject.email'    => 'wrong@example.com',
			)
		);

		$this->assertSame( NotificationTestOutcome::WOULD_SEND, $result->outcome() );
	}

	public function test_any_mode_not_matched_explains_every_clause(): void {
		[ $bot, $destination ] = $this->eligible_bot_and_destination();
		$rules                 = $this->rules();
		$rule                  = $this->save_rule(
			$rules,
			$bot->id(),
			$destination->id(),
			array(
				array(
					'field'    => 'subject.username',
					'operator' => 'equals',
					'value'    => 'jsmith',
				),
				array(
					'field'    => 'subject.email',
					'operator' => 'equals',
					'value'    => 'jane@example.com',
				),
			),
			'any'
		);

		$result = $this->tester( $rules )->test_rule(
			$rule,
			array(
				'subject.username' => 'someone-else',
				'subject.email'    => 'wrong@example.com',
			)
		);

		$this->assertSame( NotificationTestOutcome::NOT_MATCHED, $result->outcome() );
		$this->assertCount( 2, $result->failing_reasons() );
	}

	public function test_an_absent_field_is_explained_distinctly_from_a_non_matching_value(): void {
		[ $bot, $destination ] = $this->eligible_bot_and_destination();
		$rules                 = $this->rules();
		$rule                  = $this->save_rule(
			$rules,
			$bot->id(),
			$destination->id(),
			array(
				array(
					'field'    => 'subject.email',
					'operator' => 'equals',
					'value'    => 'jane@example.com',
				),
			)
		);

		// No 'subject.email' key supplied at all, so the field is genuinely
		// absent from the synthetic scenario rather than merely different.
		$result = $this->tester( $rules )->test_rule( $rule, array() );

		$this->assertSame( NotificationTestOutcome::NOT_MATCHED, $result->outcome() );
		$this->assertStringContainsString( 'no value for it', $result->failing_reasons()[0] );
	}

	public function test_a_disabled_rule_is_never_evaluated_for_a_match(): void {
		[ $bot, $destination ] = $this->eligible_bot_and_destination();
		$rules                 = $this->rules();
		// No conditions at all — would otherwise always match.
		$rule = $this->save_rule( $rules, $bot->id(), $destination->id(), array(), 'all', false );

		$result = $this->tester( $rules )->test_rule( $rule, array() );

		$this->assertSame( NotificationTestOutcome::DISABLED, $result->outcome() );
		$this->assertNull( $result->rendered_preview() );
	}

	public function test_a_matched_rule_whose_bot_is_not_active_is_destination_ineligible(): void {
		$bot = Plugin::instance()->bot_profile_repository()->create( 'Bot', str_repeat( 'b', 46 ) );
		// Deliberately left at its default UNCONFIGURED status.
		$destination = Plugin::instance()->destination_repository()->create( $bot->id(), DestinationKind::GROUP, '-100999', null, 'Ops' );

		$rules = $this->rules();
		$rule  = $this->save_rule( $rules, $bot->id(), $destination->id(), array() );

		$result = $this->tester( $rules )->test_rule( $rule, array() );

		$this->assertSame( NotificationTestOutcome::DESTINATION_INELIGIBLE, $result->outcome() );
	}

	public function test_a_matched_rule_whose_destination_is_disabled_is_destination_ineligible(): void {
		[ $bot, $destination ] = $this->eligible_bot_and_destination();
		Plugin::instance()->destination_repository()->set_enabled( $destination->id(), false );

		$rules  = $this->rules();
		$rule   = $this->save_rule( $rules, $bot->id(), $destination->id(), array() );
		$result = $this->tester( $rules )->test_rule( $rule, array() );

		$this->assertSame( NotificationTestOutcome::DESTINATION_INELIGIBLE, $result->outcome() );
	}

	public function test_a_matched_eligible_rule_with_an_empty_template_is_template_invalid(): void {
		[ $bot, $destination ] = $this->eligible_bot_and_destination();
		$rules                 = $this->rules();
		$rule                  = $this->save_rule( $rules, $bot->id(), $destination->id(), array(), 'all', true, '' );

		$result = $this->tester( $rules )->test_rule( $rule, array() );

		$this->assertSame( NotificationTestOutcome::TEMPLATE_INVALID, $result->outcome() );
		$this->assertNull( $result->rendered_preview() );
	}

	public function test_a_legacy_rule_with_an_operator_the_friendly_builder_never_offers_is_flagged_but_still_genuinely_evaluated(): void {
		[ $bot, $destination ] = $this->eligible_bot_and_destination();
		$rules                 = $this->rules();
		// 'in' is a real, engine-valid ConditionOperator (RuleEvaluator
		// evaluates it correctly) but FieldTypeCatalog never offers it for
		// any field's own permitted operator list, so RuleEditor treats a
		// rule using it as unrepresentable by the visual builder — the
		// exact "legacy rule, still testable via its stored conditions"
		// case this flag exists for (M08.2 plan §2/§8 item 10).
		$rule = $this->save_rule(
			$rules,
			$bot->id(),
			$destination->id(),
			array(
				array(
					'field'    => 'subject.username',
					'operator' => 'in',
					'value'    => array( 'jsmith', 'jdoe' ),
				),
			)
		);

		$matching_result = $this->tester( $rules )->test_rule( $rule, array( 'subject.username' => 'jsmith' ) );
		$this->assertTrue( $matching_result->has_unrepresentable_legacy_conditions() );
		$this->assertSame( NotificationTestOutcome::WOULD_SEND, $matching_result->outcome() );

		$non_matching_result = $this->tester( $rules )->test_rule( $rule, array( 'subject.username' => 'someone-else' ) );
		$this->assertTrue( $non_matching_result->has_unrepresentable_legacy_conditions() );
		$this->assertSame( NotificationTestOutcome::NOT_MATCHED, $non_matching_result->outcome() );
	}

	public function test_test_event_returns_an_empty_array_when_no_enabled_rules_exist(): void {
		$rules = $this->rules();

		$this->assertSame( array(), $this->tester( $rules )->test_event( self::EVENT_TYPE, array() ) );
	}

	public function test_test_event_evaluates_every_enabled_rule_for_that_event_type(): void {
		[ $bot, $destination ] = $this->eligible_bot_and_destination();
		$rules                 = $this->rules();
		$this->save_rule( $rules, $bot->id(), $destination->id(), array(), 'all', true, 'A' );
		$this->save_rule( $rules, $bot->id(), $destination->id(), array(), 'all', true, 'B' );
		$this->save_rule( $rules, $bot->id(), $destination->id(), array(), 'all', false, 'C' );

		$results = $this->tester( $rules )->test_event( self::EVENT_TYPE, array() );

		// Only the two enabled rules are evaluated at all, matching
		// NotificationRuleRepository::for_event_type()'s own enabled-only
		// contract — a disabled rule is never surfaced by test_event().
		$this->assertCount( 2, $results );
	}

	/**
	 * The runtime half of the no-side-effect contract (§6/§8 item 13):
	 * every scenario above ran against real repositories, yet none of them
	 * wrote a notification_dispatch_log, event_history, or audit_log row.
	 */
	public function test_no_scenario_in_this_suite_writes_dispatch_log_event_history_or_audit_log_rows(): void {
		global $wpdb;

		$before_dispatch_log  = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . Migrator::DISPATCH_LOG_TABLE ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed table name, never user input.
		$before_event_history = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . Migrator::EVENT_HISTORY_TABLE ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed table name, never user input.
		$before_audit_log     = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . Migrator::AUDIT_LOG_TABLE ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed table name, never user input.

		[ $bot, $destination ] = $this->eligible_bot_and_destination();
		$rules                 = $this->rules();
		$rule                  = $this->save_rule(
			$rules,
			$bot->id(),
			$destination->id(),
			array(
				array(
					'field'    => 'subject.username',
					'operator' => 'equals',
					'value'    => 'jsmith',
				),
			)
		);

		$tester = $this->tester( $rules );
		$tester->test_rule( $rule, array( 'subject.username' => 'jsmith' ) );
		$tester->test_rule( $rule, array( 'subject.username' => 'someone-else' ) );
		$tester->test_event( self::EVENT_TYPE, array() );

		$this->assertSame( $before_dispatch_log, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . Migrator::DISPATCH_LOG_TABLE ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed table name, never user input.
		$this->assertSame( $before_event_history, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . Migrator::EVENT_HISTORY_TABLE ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed table name, never user input.
		$this->assertSame( $before_audit_log, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . Migrator::AUDIT_LOG_TABLE ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed table name, never user input.
	}
}
