<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Events;

use UniversalTelegram\Automations\Digest\DigestEligibility;
use UniversalTelegram\Automations\Digest\VisitorDigestAggregator;
use UniversalTelegram\Automations\Digest\VisitorDigestCounterRepository;
use UniversalTelegram\Automations\Digest\VisitorDigestStateRepository;
use UniversalTelegram\Automations\RuleEvaluator;
use UniversalTelegram\Events\EventDispatcher;
use UniversalTelegram\Events\EventEnvelope;
use UniversalTelegram\Events\EventHistoryRepository;
use UniversalTelegram\Events\EventSource;
use UniversalTelegram\Events\Registry;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Privacy\Classification;
use UniversalTelegram\Privacy\Redactor;
use WP_UnitTestCase;

final class EventDispatcherTest extends WP_UnitTestCase {

	private function registry(): Registry {
		$registry = new Registry();
		$registry->register(
			'visitor.page_viewed',
			1,
			array(
				'subject.path'      => Classification::PUBLIC,
				'subject.page_type' => Classification::PUBLIC,
			),
			array( 'subject.path', 'subject.page_type' ),
			array( 'subject.path', 'subject.page_type' )
		);

		return $registry;
	}

	private function envelope( Registry $registry ): EventEnvelope {
		return new EventEnvelope( $registry, 'visitor.page_viewed', 'key-1', EventSource::VISITOR, array(), array( 'path' => '/', 'page_type' => 'home' ), array(), array() );
	}

	/**
	 * EventHistoryRepository is a final class (cannot be doubled by
	 * PHPUnit's createMock() — the same constraint
	 * Automations\NotificationDispatcher's own docblock already documents),
	 * so every test here constructs a real instance against the test
	 * database, matching EventEmitterTest's own existing precedent.
	 */
	private function history( Registry $registry ): EventHistoryRepository {
		return new EventHistoryRepository( new SchemaHealth(), $registry, new Redactor() );
	}

	/**
	 * A digest-eligible event, evaluated while the digest is active,
	 * increments exactly one counter row — proving EventDispatcher::handle()
	 * actually calls its digest aggregator, not merely that the aggregator
	 * works in isolation (M11A §9 WP3).
	 */
	public function test_an_eligible_event_increments_exactly_one_row_when_active(): void {
		$registry = $this->registry();
		$counters = new VisitorDigestCounterRepository( new SchemaHealth() );
		$state    = new VisitorDigestStateRepository( new SchemaHealth() );

		$eligibility = $this->createMock( DigestEligibility::class );
		$eligibility->method( 'is_active' )->willReturn( true );

		$aggregator     = new VisitorDigestAggregator( $eligibility, $counters, $state );
		$rule_evaluator = $this->createMock( RuleEvaluator::class );

		$dispatcher = new EventDispatcher( $this->history( $registry ), $rule_evaluator, $aggregator );
		$dispatcher->handle( $this->envelope( $registry ) );

		$window = $state->current_window_started_at();
		$this->assertNotNull( $window );
		$this->assertSame( 1, $counters->sum_for_window( $window ) );
	}

	public function test_no_row_is_incremented_when_the_digest_is_inactive(): void {
		$registry = $this->registry();
		$counters = new VisitorDigestCounterRepository( new SchemaHealth() );
		$state    = new VisitorDigestStateRepository( new SchemaHealth() );

		$eligibility = $this->createMock( DigestEligibility::class );
		$eligibility->method( 'is_active' )->willReturn( false );

		$aggregator     = new VisitorDigestAggregator( $eligibility, $counters, $state );
		$rule_evaluator = $this->createMock( RuleEvaluator::class );

		$dispatcher = new EventDispatcher( $this->history( $registry ), $rule_evaluator, $aggregator );
		$dispatcher->handle( $this->envelope( $registry ) );

		$this->assertNull( $state->current_window_started_at() );
	}

	/**
	 * A null digest aggregator (the pre-M11A construction signature some
	 * test doubles still use) leaves handle() behaving exactly as it did
	 * before M11A — history and rule evaluation still run, nothing errors.
	 */
	public function test_handle_still_works_with_no_digest_aggregator_supplied(): void {
		$registry       = $this->registry();
		$rule_evaluator = $this->createMock( RuleEvaluator::class );
		$rule_evaluator->expects( $this->once() )->method( 'evaluate' );

		$dispatcher = new EventDispatcher( $this->history( $registry ), $rule_evaluator );
		$dispatcher->handle( $this->envelope( $registry ) );
	}
}
