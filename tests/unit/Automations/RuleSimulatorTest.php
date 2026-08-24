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
use UniversalTelegram\Automations\RuleSimulator;
use UniversalTelegram\Events\Registry;
use UniversalTelegram\Privacy\Classification;

final class RuleSimulatorTest extends TestCase {

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

	private function rule( int $id, array $conditions = array(), int $priority = 100 ): NotificationRule {
		return new NotificationRule( $id, "Rule {$id}", 'wordpress.post_published', 1, $conditions, 'all', 1, 1, 'x', true, $priority, 0, 'now', 'now' );
	}

	public function test_simulation_never_invokes_the_dispatcher_or_the_dispatch_log(): void {
		$registry = $this->registry();
		$rule     = $this->rule( 1 );

		$rules_repo = $this->createMock( NotificationRuleRepository::class );
		$rules_repo->method( 'for_event_type' )->willReturn( array( $rule ) );

		$dispatch_log = $this->createMock( DispatchLogRepository::class );
		$dispatch_log->expects( $this->never() )->method( 'claim_or_reject' );
		$dispatch_log->expects( $this->never() )->method( 'record_rejected' );
		$dispatch_log->expects( $this->never() )->method( 'update' );

		$dispatcher = $this->createMock( NotificationDispatcher::class );
		$dispatcher->expects( $this->never() )->method( 'dispatch' );

		$simulator = new RuleSimulator( $rules_repo, $registry, $dispatch_log, $dispatcher );
		$result    = $simulator->simulate( 'wordpress.post_published', array( 'subject' => array( 'post_id' => 5 ) ), 'sample-key' );

		$this->assertSame(
			array(
				array(
					'rule_id'     => 1,
					'rule_name'   => 'Rule 1',
					'outcome'     => 'matched',
					'reason_code' => null,
				),
			),
			$result->entries()
		);
	}

	public function test_outcome_ordering_matches_real_evaluation_ordering(): void {
		$registry = $this->registry();
		$rules    = array( $this->rule( 2, array(), 50 ), $this->rule( 3, array(), 50 ), $this->rule( 1, array(), 100 ) );

		$rules_repo = $this->createMock( NotificationRuleRepository::class );
		// The repository's own ORDER BY (priority ASC, id ASC) is what
		// RuleEvaluator/RuleSimulator both rely on; the fake here returns
		// rows already in that order, matching for_event_type()'s real
		// contract.
		$rules_repo->method( 'for_event_type' )->willReturn( $rules );

		$simulator = new RuleSimulator( $rules_repo, $registry, $this->createMock( DispatchLogRepository::class ), $this->createMock( NotificationDispatcher::class ) );
		$result    = $simulator->simulate( 'wordpress.post_published', array( 'subject' => array( 'post_id' => 1 ) ), 'sample-key' );

		$ids = array_column( $result->entries(), 'rule_id' );
		$this->assertSame( array( 2, 3, 1 ), $ids );
	}

	public function test_no_rows_are_ever_written_because_simulation_uses_a_transient_envelope_only(): void {
		$registry   = $this->registry();
		$rules_repo = $this->createMock( NotificationRuleRepository::class );
		$rules_repo->method( 'for_event_type' )->willReturn( array() );

		$dispatch_log = $this->createMock( DispatchLogRepository::class );
		$dispatch_log->expects( $this->never() )->method( $this->anything() );

		$simulator = new RuleSimulator( $rules_repo, $registry, $dispatch_log, $this->createMock( NotificationDispatcher::class ) );
		$result    = $simulator->simulate( 'wordpress.post_published', array(), 'sample-key' );

		$this->assertSame( array(), $result->entries() );
		$this->assertNull( $result->error_code() );
	}

	/**
	 * Previewing a digest-eligible event type while the digest is active
	 * must honestly report that the rule would be suppressed, not
	 * "matched" — and must still never touch the dispatch log (M11A §3.1,
	 * §9 WP2).
	 */
	public function test_simulating_a_digest_eligible_event_type_reports_suppression_when_the_digest_is_active(): void {
		$registry = new Registry();
		$registry->register(
			'visitor.page_viewed',
			1,
			array( 'subject.path' => Classification::PUBLIC ),
			array( 'subject.path' ),
			array( 'subject.path' )
		);
		$rule = new NotificationRule( 1, 'Rule 1', 'visitor.page_viewed', 1, array(), 'all', 1, 1, 'x', true, 100, 0, 'now', 'now' );

		$rules_repo = $this->createMock( NotificationRuleRepository::class );
		$rules_repo->method( 'for_event_type' )->willReturn( array( $rule ) );

		$dispatch_log = $this->createMock( DispatchLogRepository::class );
		$dispatch_log->expects( $this->never() )->method( $this->anything() );

		$dispatcher = $this->createMock( NotificationDispatcher::class );
		$dispatcher->expects( $this->never() )->method( 'dispatch' );

		$eligibility = $this->createMock( DigestEligibility::class );
		$eligibility->method( 'is_active' )->willReturn( true );

		$simulator = new RuleSimulator( $rules_repo, $registry, $dispatch_log, $dispatcher, $eligibility );
		$result    = $simulator->simulate( 'visitor.page_viewed', array( 'subject' => array( 'path' => '/' ) ), 'sample-key' );

		$this->assertSame(
			array(
				array(
					'rule_id'     => 1,
					'rule_name'   => 'Rule 1',
					'outcome'     => 'rejected',
					'reason_code' => RuleEvaluator::SUPPRESSED_BY_DIGEST_REASON_CODE,
				),
			),
			$result->entries()
		);
	}

	/**
	 * ADR-0032: a simulated preview must not report "matched" for a
	 * not-equals condition whose field the sample data simply omits — a
	 * present-only-when-configured field is honestly evaluated as absent,
	 * exactly like real evaluation via RuleEvaluator.
	 */
	public function test_simulating_a_not_equals_condition_on_an_absent_sample_field_reports_rejected(): void {
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

		$rules_repo = $this->createMock( NotificationRuleRepository::class );
		$rules_repo->method( 'for_event_type' )->willReturn( array( $rule ) );

		$simulator = new RuleSimulator( $rules_repo, $registry, $this->createMock( DispatchLogRepository::class ), $this->createMock( NotificationDispatcher::class ) );
		$result    = $simulator->simulate( 'wordpress.post_published', array( 'subject' => array( 'post_id' => 5 ) ), 'sample-key' );

		$this->assertSame(
			array(
				array(
					'rule_id'     => 1,
					'rule_name'   => 'Rule 1',
					'outcome'     => 'rejected',
					'reason_code' => 'condition_not_matched',
				),
			),
			$result->entries()
		);
	}

	public function test_an_unregistered_event_type_yields_an_error_code_not_a_fatal(): void {
		$registry   = new Registry();
		$rules_repo = $this->createMock( NotificationRuleRepository::class );

		$simulator = new RuleSimulator( $rules_repo, $registry, $this->createMock( DispatchLogRepository::class ), $this->createMock( NotificationDispatcher::class ) );
		$result    = $simulator->simulate( 'wordpress.never_registered', array(), 'sample-key' );

		$this->assertNotNull( $result->error_code() );
		$this->assertSame( array(), $result->entries() );
	}
}
