<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Automations;

use PHPUnit\Framework\TestCase;
use UniversalTelegram\Automations\Digest\DigestEligibility;
use UniversalTelegram\Automations\DispatchLogRepository;
use UniversalTelegram\Automations\NotificationDispatcher;
use UniversalTelegram\Automations\NotificationRule;
use UniversalTelegram\Automations\NotificationRuleRepository;
use UniversalTelegram\Automations\RuleEvaluator;
use UniversalTelegram\Events\EventEnvelope;
use UniversalTelegram\Events\EventSource;
use UniversalTelegram\Events\Registry;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Privacy\Classification;

final class RuleEvaluatorTest extends TestCase {

	private function fake_dispatch_log(): DispatchLogRepository {
		return $this->createMock( DispatchLogRepository::class );
	}

	private function fake_dispatcher(): NotificationDispatcher {
		return $this->createMock( NotificationDispatcher::class );
	}

	private function registry(): Registry {
		$registry = new Registry();
		$registry->register(
			'wordpress.post_published',
			1,
			array(
				'subject.post_id'   => Classification::PUBLIC,
				'context.post_type' => Classification::PUBLIC,
			),
			array( 'subject.post_id', 'context.post_type' ),
			array( 'subject.post_id', 'context.post_type' )
		);

		return $registry;
	}

	private function rule( int $id, array $conditions = array(), int $priority = 100, string $match_mode = 'all' ): NotificationRule {
		return new NotificationRule( $id, "Rule {$id}", 'wordpress.post_published', 1, $conditions, $match_mode, 1, 1, 'x', true, $priority, 0, 'now', 'now' );
	}

	private function envelope( Registry $registry, int $post_id = 1 ): EventEnvelope {
		return new EventEnvelope( $registry, 'wordpress.post_published', 'key-' . $post_id, EventSource::WORDPRESS_CORE, array(), array( 'post_id' => $post_id ), array(), array() );
	}

	public function test_rules_are_evaluated_in_deterministic_priority_then_id_order(): void {
		$registry = $this->registry();
		$rules    = array( $this->rule( 2, array(), 50 ), $this->rule( 1, array(), 100 ), $this->rule( 3, array(), 50 ) );

		$repo = $this->createMock( NotificationRuleRepository::class );
		$repo->method( 'for_event_type' )->willReturn( array( $rules[0], $rules[2], $rules[1] ) );

		$order     = array();
		$evaluator = new class( $repo, $registry, $this->fake_dispatch_log(), $this->fake_dispatcher(), $order ) extends RuleEvaluator {
			private array $order_ref;

			public function __construct( $repo, $registry, $dispatch_log, $dispatcher, array &$order_ref ) {
				parent::__construct( $repo, $registry, $dispatch_log, $dispatcher );
				$this->order_ref = &$order_ref;
			}

			protected function on_matched( NotificationRule $rule, EventEnvelope $event ): void {
				$this->order_ref[] = $rule->id();
			}
		};

		$evaluator->evaluate( $this->envelope( $registry ) );

		$this->assertSame( array( 2, 3, 1 ), $order );
	}

	public function test_a_single_event_can_independently_match_multiple_rules(): void {
		$registry = $this->registry();
		$rules    = array( $this->rule( 1 ), $this->rule( 2 ) );

		$repo = $this->createMock( NotificationRuleRepository::class );
		$repo->method( 'for_event_type' )->willReturn( $rules );

		$matched   = array();
		$evaluator = new class( $repo, $registry, $this->fake_dispatch_log(), $this->fake_dispatcher(), $matched ) extends RuleEvaluator {
			private array $matched_ref;

			public function __construct( $repo, $registry, $dispatch_log, $dispatcher, array &$matched_ref ) {
				parent::__construct( $repo, $registry, $dispatch_log, $dispatcher );
				$this->matched_ref = &$matched_ref;
			}

			protected function on_matched( NotificationRule $rule, EventEnvelope $event ): void {
				$this->matched_ref[] = $rule->id();
			}
		};

		$evaluator->evaluate( $this->envelope( $registry ) );

		$this->assertSame( array( 1, 2 ), $matched );
	}

	public function test_one_rule_throwing_does_not_prevent_the_next_rule_from_evaluating(): void {
		$registry = $this->registry();
		$rules    = array( $this->rule( 1 ), $this->rule( 2 ) );

		$repo = $this->createMock( NotificationRuleRepository::class );
		$repo->method( 'for_event_type' )->willReturn( $rules );

		$matched   = array();
		$evaluator = new class( $repo, $registry, $this->fake_dispatch_log(), $this->fake_dispatcher(), $matched ) extends RuleEvaluator {
			private array $matched_ref;

			public function __construct( $repo, $registry, $dispatch_log, $dispatcher, array &$matched_ref ) {
				parent::__construct( $repo, $registry, $dispatch_log, $dispatcher );
				$this->matched_ref = &$matched_ref;
			}

			protected function on_matched( NotificationRule $rule, EventEnvelope $event ): void {
				if ( 1 === $rule->id() ) {
					throw new \RuntimeException( 'Simulated failure for rule 1.' );
				}
				$this->matched_ref[] = $rule->id();
			}
		};

		$evaluator->evaluate( $this->envelope( $registry ) );

		$this->assertSame( array( 2 ), $matched );
	}

	public function test_a_rule_with_a_matching_condition_is_matched(): void {
		$registry = $this->registry();
		$rule     = $this->rule(
			1,
			array(
				array(
					'field'    => 'subject.post_id',
					'operator' => 'equals',
					'value'    => 5,
				),
			)
		);

		$repo = $this->createMock( NotificationRuleRepository::class );
		$repo->method( 'for_event_type' )->willReturn( array( $rule ) );

		$matched   = array();
		$rejected  = array();
		$evaluator = $this->recording_evaluator( $repo, $registry, $matched, $rejected );

		$evaluator->evaluate( $this->envelope( $registry, 5 ) );

		$this->assertSame( array( 1 ), $matched );
		$this->assertSame( array(), $rejected );
	}

	public function test_a_rule_with_a_non_matching_condition_is_rejected(): void {
		$registry = $this->registry();
		$rule     = $this->rule(
			1,
			array(
				array(
					'field'    => 'subject.post_id',
					'operator' => 'equals',
					'value'    => 999,
				),
			)
		);

		$repo = $this->createMock( NotificationRuleRepository::class );
		$repo->method( 'for_event_type' )->willReturn( array( $rule ) );

		$matched   = array();
		$rejected  = array();
		$evaluator = $this->recording_evaluator( $repo, $registry, $matched, $rejected );

		$evaluator->evaluate( $this->envelope( $registry, 5 ) );

		$this->assertSame( array(), $matched );
		$this->assertSame( array( 1 ), $rejected );
	}

	public function test_an_unknown_operator_is_rejected_as_invalid_configuration(): void {
		$registry = $this->registry();
		$rule     = $this->rule(
			1,
			array(
				array(
					'field'    => 'subject.post_id',
					'operator' => 'not_a_real_operator',
					'value'    => 1,
				),
			)
		);

		$repo = $this->createMock( NotificationRuleRepository::class );
		$repo->method( 'for_event_type' )->willReturn( array( $rule ) );

		$matched   = array();
		$rejected  = array();
		$evaluator = $this->recording_evaluator( $repo, $registry, $matched, $rejected );

		$evaluator->evaluate( $this->envelope( $registry, 5 ) );

		$this->assertSame( array(), $matched );
		$this->assertSame( array( 1 ), $rejected );
	}

	/**
	 * ADR-0032: a condition on an absent event field never matches — not
	 * even "is not," which would otherwise loosely-equal-compare against
	 * null and appear to differ from the configured value.
	 */
	public function test_a_not_equals_condition_on_an_absent_field_never_matches(): void {
		$registry = $this->registry();
		$rule     = $this->rule(
			1,
			array(
				array(
					'field'    => 'context.post_type',
					'operator' => 'not_equals',
					'value'    => 'page',
				),
			)
		);

		$repo = $this->createMock( NotificationRuleRepository::class );
		$repo->method( 'for_event_type' )->willReturn( array( $rule ) );

		$matched   = array();
		$rejected  = array();
		$evaluator = $this->recording_evaluator( $repo, $registry, $matched, $rejected );

		$evaluator->evaluate( $this->envelope( $registry, 5 ) );

		$this->assertSame( array(), $matched );
		$this->assertSame( array( 1 ), $rejected );
	}

	public function test_an_empty_condition_list_always_matches(): void {
		$registry = $this->registry();
		$rule     = $this->rule( 1, array() );

		$repo = $this->createMock( NotificationRuleRepository::class );
		$repo->method( 'for_event_type' )->willReturn( array( $rule ) );

		$matched   = array();
		$rejected  = array();
		$evaluator = $this->recording_evaluator( $repo, $registry, $matched, $rejected );

		$evaluator->evaluate( $this->envelope( $registry, 5 ) );

		$this->assertSame( array( 1 ), $matched );
		$this->assertSame( array(), $rejected );
	}

	public function test_any_mode_matches_when_at_least_one_present_field_clause_matches(): void {
		$registry = $this->registry();
		$rule     = $this->rule(
			1,
			array(
				array(
					'field'    => 'subject.post_id',
					'operator' => 'equals',
					'value'    => 999,
				),
				array(
					'field'    => 'subject.post_id',
					'operator' => 'equals',
					'value'    => 5,
				),
			),
			100,
			'any'
		);

		$repo = $this->createMock( NotificationRuleRepository::class );
		$repo->method( 'for_event_type' )->willReturn( array( $rule ) );

		$matched   = array();
		$rejected  = array();
		$evaluator = $this->recording_evaluator( $repo, $registry, $matched, $rejected );

		$evaluator->evaluate( $this->envelope( $registry, 5 ) );

		$this->assertSame( array( 1 ), $matched );
		$this->assertSame( array(), $rejected );
	}

	public function test_any_mode_does_not_match_when_every_clauses_field_is_absent(): void {
		$registry = $this->registry();
		$rule     = $this->rule(
			1,
			array(
				array(
					'field'    => 'context.post_type',
					'operator' => 'not_equals',
					'value'    => 'page',
				),
			),
			100,
			'any'
		);

		$repo = $this->createMock( NotificationRuleRepository::class );
		$repo->method( 'for_event_type' )->willReturn( array( $rule ) );

		$matched   = array();
		$rejected  = array();
		$evaluator = $this->recording_evaluator( $repo, $registry, $matched, $rejected );

		$evaluator->evaluate( $this->envelope( $registry, 5 ) );

		$this->assertSame( array(), $matched );
		$this->assertSame( array( 1 ), $rejected );
	}

	public function test_any_mode_does_not_match_when_no_present_field_clause_matches(): void {
		$registry = $this->registry();
		$rule     = $this->rule(
			1,
			array(
				array(
					'field'    => 'subject.post_id',
					'operator' => 'equals',
					'value'    => 999,
				),
			),
			100,
			'any'
		);

		$repo = $this->createMock( NotificationRuleRepository::class );
		$repo->method( 'for_event_type' )->willReturn( array( $rule ) );

		$matched   = array();
		$rejected  = array();
		$evaluator = $this->recording_evaluator( $repo, $registry, $matched, $rejected );

		$evaluator->evaluate( $this->envelope( $registry, 5 ) );

		$this->assertSame( array(), $matched );
		$this->assertSame( array( 1 ), $rejected );
	}

	public function test_all_mode_is_unchanged_when_every_clause_matches(): void {
		$registry = $this->registry();
		$rule     = $this->rule(
			1,
			array(
				array(
					'field'    => 'subject.post_id',
					'operator' => 'equals',
					'value'    => 5,
				),
			),
			100,
			'all'
		);

		$repo = $this->createMock( NotificationRuleRepository::class );
		$repo->method( 'for_event_type' )->willReturn( array( $rule ) );

		$matched   = array();
		$rejected  = array();
		$evaluator = $this->recording_evaluator( $repo, $registry, $matched, $rejected );

		$evaluator->evaluate( $this->envelope( $registry, 5 ) );

		$this->assertSame( array( 1 ), $matched );
		$this->assertSame( array(), $rejected );
	}

	private function visitor_registry(): Registry {
		$registry = new Registry();
		$registry->register(
			'visitor.page_viewed',
			1,
			array( 'subject.path' => Classification::PUBLIC ),
			array( 'subject.path' ),
			array( 'subject.path' )
		);

		return $registry;
	}

	private function visitor_rule( int $id ): NotificationRule {
		return new NotificationRule( $id, "Rule {$id}", 'visitor.page_viewed', 1, array(), 'all', 1, 1, 'x', true, 100, 0, 'now', 'now' );
	}

	private function visitor_envelope( Registry $registry ): EventEnvelope {
		return new EventEnvelope( $registry, 'visitor.page_viewed', 'key-1', EventSource::VISITOR, array(), array( 'path' => '/' ), array(), array() );
	}

	/**
	 * When DigestEligibility::is_active() is true for a digest-eligible
	 * visitor event type, no rule for that type reaches
	 * NotificationDispatcher::dispatch() — each is instead recorded
	 * rejected with RuleEvaluator::SUPPRESSED_BY_DIGEST_REASON_CODE
	 * (M11A §3.1).
	 */
	public function test_a_digest_eligible_rule_is_suppressed_while_the_digest_is_active(): void {
		$registry = $this->visitor_registry();
		$rule     = $this->visitor_rule( 1 );

		$repo = $this->createMock( NotificationRuleRepository::class );
		$repo->method( 'for_event_type' )->willReturn( array( $rule ) );

		$dispatch_log = $this->createMock( DispatchLogRepository::class );
		$dispatch_log->expects( $this->once() )
			->method( 'record_rejected' )
			->with( 1, $this->anything(), RuleEvaluator::SUPPRESSED_BY_DIGEST_REASON_CODE );

		$dispatcher = $this->fake_dispatcher();
		$dispatcher->expects( $this->never() )->method( 'dispatch' );

		$eligibility = $this->createMock( DigestEligibility::class );
		$eligibility->method( 'is_active' )->willReturn( true );

		$evaluator = new RuleEvaluator( $repo, $registry, $dispatch_log, $dispatcher, $eligibility );
		$evaluator->evaluate( $this->visitor_envelope( $registry ) );
	}

	/**
	 * When the digest is disabled (or enabled with an invalid target —
	 * is_active() false either way), a digest-eligible rule dispatches
	 * exactly as it did before M11A.
	 */
	public function test_a_digest_eligible_rule_dispatches_normally_while_the_digest_is_inactive(): void {
		$registry = $this->visitor_registry();
		$rule     = $this->visitor_rule( 1 );

		$repo = $this->createMock( NotificationRuleRepository::class );
		$repo->method( 'for_event_type' )->willReturn( array( $rule ) );

		$dispatch_log = $this->fake_dispatch_log();

		$dispatcher = $this->fake_dispatcher();
		$dispatcher->expects( $this->once() )->method( 'dispatch' );

		$eligibility = $this->createMock( DigestEligibility::class );
		$eligibility->method( 'is_active' )->willReturn( false );

		$evaluator = new RuleEvaluator( $repo, $registry, $dispatch_log, $dispatcher, $eligibility );
		$evaluator->evaluate( $this->visitor_envelope( $registry ) );
	}

	/**
	 * Visitor.javascript_error is deliberately excluded from
	 * DigestEligibility::SUPPRESSED_EVENT_TYPES (M11A §3.3) — its rules
	 * must keep dispatching individually even while the digest is fully
	 * active for every other visitor event type.
	 */
	public function test_javascript_error_rules_are_never_suppressed_by_the_digest(): void {
		$registry = new Registry();
		$registry->register(
			'visitor.javascript_error',
			1,
			array( 'payload.error_category' => Classification::PUBLIC ),
			array( 'payload.error_category' ),
			array( 'payload.error_category' )
		);
		$rule = new NotificationRule( 1, 'Rule 1', 'visitor.javascript_error', 1, array(), 'all', 1, 1, 'x', true, 100, 0, 'now', 'now' );

		$repo = $this->createMock( NotificationRuleRepository::class );
		$repo->method( 'for_event_type' )->willReturn( array( $rule ) );

		$dispatcher = $this->fake_dispatcher();
		$dispatcher->expects( $this->once() )->method( 'dispatch' );

		$eligibility = $this->createMock( DigestEligibility::class );
		$eligibility->method( 'is_active' )->willReturn( true );

		$evaluator = new RuleEvaluator( $repo, $registry, $this->fake_dispatch_log(), $dispatcher, $eligibility );
		$evaluator->evaluate(
			new EventEnvelope( $registry, 'visitor.javascript_error', 'key-1', EventSource::VISITOR, array(), array(), array(), array( 'error_category' => 'runtime' ) )
		);
	}

	private function recording_evaluator( $repo, Registry $registry, array &$matched, array &$rejected ): RuleEvaluator {
		return new class( $repo, $registry, $this->fake_dispatch_log(), $this->fake_dispatcher(), $matched, $rejected ) extends RuleEvaluator {
			private array $matched_ref;
			private array $rejected_ref;

			public function __construct( $repo, $registry, $dispatch_log, $dispatcher, array &$matched_ref, array &$rejected_ref ) {
				parent::__construct( $repo, $registry, $dispatch_log, $dispatcher );
				$this->matched_ref  = &$matched_ref;
				$this->rejected_ref = &$rejected_ref;
			}

			protected function on_matched( NotificationRule $rule, EventEnvelope $event ): void {
				$this->matched_ref[] = $rule->id();
			}

			protected function on_rejected( NotificationRule $rule, EventEnvelope $event, string $reason_code ): void {
				$this->rejected_ref[] = $rule->id();
			}
		};
	}
}
