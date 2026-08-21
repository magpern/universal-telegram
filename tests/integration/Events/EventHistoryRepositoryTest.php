<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Events;

use UniversalTelegram\Events\EventEnvelope;
use UniversalTelegram\Events\EventHistoryRepository;
use UniversalTelegram\Events\EventSource;
use UniversalTelegram\Events\Registry;
use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Privacy\Classification;
use UniversalTelegram\Privacy\Redactor;
use WP_UnitTestCase;

final class EventHistoryRepositoryTest extends WP_UnitTestCase {

	private function registry(): Registry {
		$registry = new Registry();
		$registry->register(
			'wordpress.user_registered',
			1,
			array(
				'subject.user_id' => Classification::PUBLIC,
				'context.ip_hash' => Classification::INTERNAL,
			),
			array( 'subject.user_id', 'context.ip_hash' ),
			array( 'subject.user_id' )
		);

		return $registry;
	}

	public function test_only_history_projection_fields_appear_in_the_stored_row(): void {
		global $wpdb;

		$registry = $this->registry();
		$repo     = new EventHistoryRepository( new SchemaHealth(), $registry, new Redactor() );

		$envelope = new EventEnvelope(
			$registry,
			'wordpress.user_registered',
			'key-1',
			EventSource::WORDPRESS_CORE,
			array(),
			array( 'user_id' => 42 ),
			array( 'ip_hash' => 'secret-ish' ),
			array()
		);

		$repo->record( $envelope );

		$table = $wpdb->prefix . Migrator::EVENT_HISTORY_TABLE;
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE event_id = %s", $envelope->event_id() ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$this->assertNotNull( $row );
		$projected = json_decode( $row['projected_fields_json'], true );

		$this->assertSame( array( 'subject' => array( 'user_id' => 42 ) ), $projected );
		$this->assertStringNotContainsString( 'secret-ish', $row['projected_fields_json'] );
		$this->assertStringNotContainsString( 'ip_hash', $row['projected_fields_json'] );
	}

	public function test_a_replayed_event_id_produces_no_second_row(): void {
		global $wpdb;

		$registry = $this->registry();
		$repo     = new EventHistoryRepository( new SchemaHealth(), $registry, new Redactor() );

		$envelope = new EventEnvelope( $registry, 'wordpress.user_registered', 'replay-key', EventSource::WORDPRESS_CORE, array(), array( 'user_id' => 1 ), array(), array() );

		$repo->record( $envelope );
		$repo->record( $envelope );

		$table = $wpdb->prefix . Migrator::EVENT_HISTORY_TABLE;
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE event_id = %s", $envelope->event_id() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$this->assertSame( 1, $count );
	}

	public function test_no_op_when_schema_unavailable(): void {
		$schema_health = new SchemaHealth();
		$schema_health->mark_unavailable( \UniversalTelegram\Persistence\MigrationFailureCode::LOCK_UNAVAILABLE );

		$registry = $this->registry();
		$repo     = new EventHistoryRepository( $schema_health, $registry, new Redactor() );

		$envelope = new EventEnvelope( $registry, 'wordpress.user_registered', 'unavailable-key', EventSource::WORDPRESS_CORE, array(), array( 'user_id' => 1 ), array(), array() );

		// Must not throw.
		$repo->record( $envelope );
		$this->assertTrue( true );
	}
}
