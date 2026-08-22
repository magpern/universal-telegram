<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Queue;

use ActionScheduler;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Queue\Dispatcher;
use UniversalTelegram\Queue\JobEnvelope;
use UniversalTelegram\Queue\QueueHealth;
use UniversalTelegram\Queue\WorkerRunner;
use WP_UnitTestCase;

final class QueueHealthTest extends WP_UnitTestCase {

	/**
	 * Action Scheduler's own tables are not wrapped by WP_UnitTestCase's
	 * per-test transaction rollback, so a pending action enqueued by an
	 * earlier test in the same run can otherwise leak into this class'
	 * own "nothing pending" assertions.
	 */
	protected function setUp(): void {
		parent::setUp();

		$ids = ActionScheduler::store()->query_actions( array( 'group' => WorkerRunner::GROUP ) );

		foreach ( (array) $ids as $id ) {
			ActionScheduler::store()->delete_action( (int) $id );
		}
	}

	public function test_oldest_pending_age_is_zero_when_nothing_is_pending(): void {
		$health = new QueueHealth();

		$this->assertSame( 0, $health->oldest_pending_age_seconds() );
	}

	public function test_oldest_pending_age_is_non_negative_for_a_just_enqueued_job(): void {
		$dispatcher = new Dispatcher( new SchemaHealth() );
		$dispatcher->enqueue( new JobEnvelope( 'test.queue_health_job', array(), array() ) );

		$health = new QueueHealth();
		$age    = $health->oldest_pending_age_seconds();

		$this->assertGreaterThanOrEqual( 0, $age );
	}
}
