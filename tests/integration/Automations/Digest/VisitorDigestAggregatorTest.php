<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Automations\Digest;

use UniversalTelegram\Automations\Digest\DigestEligibility;
use UniversalTelegram\Automations\Digest\VisitorDigestAggregator;
use UniversalTelegram\Automations\Digest\VisitorDigestCounterRepository;
use UniversalTelegram\Automations\Digest\VisitorDigestStateRepository;
use UniversalTelegram\Events\EventEnvelope;
use UniversalTelegram\Events\EventSource;
use UniversalTelegram\Events\Registry;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Privacy\Classification;
use WP_UnitTestCase;

final class VisitorDigestAggregatorTest extends WP_UnitTestCase {

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
		$registry->register(
			'visitor.search_performed',
			1,
			array( 'payload.result_count' => Classification::PUBLIC ),
			array( 'payload.result_count' ),
			array( 'payload.result_count' )
		);
		$registry->register(
			'wordpress.post_published',
			1,
			array( 'subject.post_id' => Classification::PUBLIC ),
			array( 'subject.post_id' ),
			array( 'subject.post_id' )
		);

		return $registry;
	}

	public function test_a_digest_eligible_event_increments_a_bucket_when_active(): void {
		$registry = $this->registry();
		$counters = new VisitorDigestCounterRepository( new SchemaHealth() );
		$state    = new VisitorDigestStateRepository( new SchemaHealth() );

		$eligibility = $this->createMock( DigestEligibility::class );
		$eligibility->method( 'is_active' )->willReturn( true );

		$aggregator = new VisitorDigestAggregator( $eligibility, $counters, $state );
		$aggregator->record(
			new EventEnvelope(
				$registry,
				'visitor.page_viewed',
				'key-1',
				EventSource::VISITOR,
				array(),
				array(
					'path'      => '/',
					'page_type' => 'home',
				),
				array(),
				array()
			)
		);

		$window = $state->current_window_started_at();
		$this->assertNotNull( $window );

		$rows = $counters->for_window( $window );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'page_views', $rows[0]['category'] );
		$this->assertSame( 'home', $rows[0]['page_type'] );
		$this->assertSame( 1, $rows[0]['event_count'] );
	}

	public function test_no_bucket_is_incremented_and_no_window_opens_while_inactive(): void {
		$registry = $this->registry();
		$counters = new VisitorDigestCounterRepository( new SchemaHealth() );
		$state    = new VisitorDigestStateRepository( new SchemaHealth() );

		$eligibility = $this->createMock( DigestEligibility::class );
		$eligibility->method( 'is_active' )->willReturn( false );

		$aggregator = new VisitorDigestAggregator( $eligibility, $counters, $state );
		$aggregator->record(
			new EventEnvelope( $registry, 'visitor.search_performed', 'key-1', EventSource::VISITOR, array(), array(), array(), array( 'result_count' => 3 ) )
		);

		$this->assertNull( $state->current_window_started_at() );
	}

	public function test_a_non_digest_eligible_event_type_is_ignored_even_when_the_digest_is_active(): void {
		$registry = $this->registry();
		$counters = new VisitorDigestCounterRepository( new SchemaHealth() );
		$state    = new VisitorDigestStateRepository( new SchemaHealth() );

		$eligibility = $this->createMock( DigestEligibility::class );
		$eligibility->method( 'is_active' )->willReturn( true );

		$aggregator = new VisitorDigestAggregator( $eligibility, $counters, $state );
		$aggregator->record(
			new EventEnvelope( $registry, 'wordpress.post_published', 'key-1', EventSource::WORDPRESS_CORE, array(), array( 'post_id' => 1 ), array(), array() )
		);

		$this->assertNull( $state->current_window_started_at() );
	}

	public function test_search_performed_has_no_page_type_breakdown(): void {
		$registry = $this->registry();
		$counters = new VisitorDigestCounterRepository( new SchemaHealth() );
		$state    = new VisitorDigestStateRepository( new SchemaHealth() );

		$eligibility = $this->createMock( DigestEligibility::class );
		$eligibility->method( 'is_active' )->willReturn( true );

		$aggregator = new VisitorDigestAggregator( $eligibility, $counters, $state );
		$aggregator->record(
			new EventEnvelope( $registry, 'visitor.search_performed', 'key-1', EventSource::VISITOR, array(), array(), array(), array( 'result_count' => 3 ) )
		);

		$window = $state->current_window_started_at();
		$rows   = $counters->for_window( $window );

		$this->assertSame( 'search', $rows[0]['category'] );
		$this->assertSame( '', $rows[0]['page_type'] );
	}
}
