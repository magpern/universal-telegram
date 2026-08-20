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
use UniversalTelegram\Automations\RuleSimulator;
use UniversalTelegram\Events\Registry;
use UniversalTelegram\Privacy\Classification;

final class RuleSimulatorTest extends TestCase {

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

		$this->assertSame( array( array( 'rule_id' => 1, 'rule_name' => 'Rule 1', 'outcome' => 'matched', 'reason_code' => null ) ), $result->entries() );
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

	public function test_an_unregistered_event_type_yields_an_error_code_not_a_fatal(): void {
		$registry   = new Registry();
		$rules_repo = $this->createMock( NotificationRuleRepository::class );

		$simulator = new RuleSimulator( $rules_repo, $registry, $this->createMock( DispatchLogRepository::class ), $this->createMock( NotificationDispatcher::class ) );
		$result    = $simulator->simulate( 'wordpress.never_registered', array(), 'sample-key' );

		$this->assertNotNull( $result->error_code() );
		$this->assertSame( array(), $result->entries() );
	}
}
