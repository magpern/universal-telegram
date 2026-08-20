<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Automations;

use PHPUnit\Framework\TestCase;
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
			array( 'subject.post_id' => Classification::PUBLIC ),
			array( 'subject.post_id' ),
			array( 'subject.post_id' )
		);

		return $registry;
	}

	private function rule( int $id, array $conditions = array(), int $priority = 100 ): NotificationRule {
		return new NotificationRule( $id, "Rule {$id}", 'wordpress.post_published', 1, $conditions, 1, 1, 'x', true, $priority, 0, 'now', 'now' );
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
		$rule     = $this->rule( 1, array( array( 'field' => 'subject.post_id', 'operator' => 'equals', 'value' => 5 ) ) );

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
		$rule     = $this->rule( 1, array( array( 'field' => 'subject.post_id', 'operator' => 'equals', 'value' => 999 ) ) );

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
		$rule     = $this->rule( 1, array( array( 'field' => 'subject.post_id', 'operator' => 'not_a_real_operator', 'value' => 1 ) ) );

		$repo = $this->createMock( NotificationRuleRepository::class );
		$repo->method( 'for_event_type' )->willReturn( array( $rule ) );

		$matched  = array();
		$rejected = array();
		$evaluator = $this->recording_evaluator( $repo, $registry, $matched, $rejected );

		$evaluator->evaluate( $this->envelope( $registry, 5 ) );

		$this->assertSame( array(), $matched );
		$this->assertSame( array( 1 ), $rejected );
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
