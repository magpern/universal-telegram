<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Automations;

use UniversalTelegram\Automations\DispatchLogRepository;
use UniversalTelegram\Automations\DispatchLogResult;
use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Persistence\SchemaHealth;
use WP_UnitTestCase;

final class DispatchLogRepositoryTest extends WP_UnitTestCase {

	public function test_claim_or_reject_creates_the_first_row(): void {
		$repo = new DispatchLogRepository( new SchemaHealth() );

		$result = $repo->claim_or_reject( 1, str_repeat( 'a', 64 ), DispatchLogResult::CLAIMED );

		$this->assertSame( DispatchLogResult::CLAIMED, $result );
	}

	public function test_a_second_attempt_for_the_same_pair_is_skipped_duplicate_and_writes_nothing(): void {
		global $wpdb;

		$repo     = new DispatchLogRepository( new SchemaHealth() );
		$event_id = str_repeat( 'b', 64 );

		$first  = $repo->claim_or_reject( 1, $event_id, DispatchLogResult::CLAIMED );
		$second = $repo->claim_or_reject( 1, $event_id, DispatchLogResult::CLAIMED );

		$this->assertSame( DispatchLogResult::CLAIMED, $first );
		$this->assertSame( DispatchLogResult::SKIPPED_DUPLICATE, $second );

		$table = $wpdb->prefix . Migrator::DISPATCH_LOG_TABLE;
		$count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE rule_id = %d AND event_id = %s", 1, $event_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		$this->assertSame( 1, $count );

		// The pre-existing row is left exactly as it stands.
		$stored_result = $wpdb->get_var(
			$wpdb->prepare( "SELECT result FROM {$table} WHERE rule_id = %d AND event_id = %s", 1, $event_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		$this->assertSame( DispatchLogResult::CLAIMED->value, $stored_result );
	}

	public function test_a_different_event_id_for_the_same_rule_is_independent(): void {
		$repo = new DispatchLogRepository( new SchemaHealth() );

		$first  = $repo->claim_or_reject( 1, str_repeat( 'c', 64 ), DispatchLogResult::CLAIMED );
		$second = $repo->claim_or_reject( 1, str_repeat( 'd', 64 ), DispatchLogResult::CLAIMED );

		$this->assertSame( DispatchLogResult::CLAIMED, $first );
		$this->assertSame( DispatchLogResult::CLAIMED, $second );
	}

	public function test_update_sets_the_terminal_state(): void {
		global $wpdb;

		$repo     = new DispatchLogRepository( new SchemaHealth() );
		$event_id = str_repeat( 'e', 64 );

		$repo->claim_or_reject( 1, $event_id, DispatchLogResult::CLAIMED );
		$repo->update( 1, $event_id, DispatchLogResult::HANDED_OFF_TO_M01, 'a-uuid', null );

		$table = $wpdb->prefix . Migrator::DISPATCH_LOG_TABLE;
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE rule_id = %d AND event_id = %s", 1, $event_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		$this->assertSame( 'handed_off_to_m01', $row['result'] );
		$this->assertSame( 'a-uuid', $row['outbound_message_uuid'] );
	}

	public function test_most_recent_handoff_at_ignores_claimed_rows(): void {
		$repo = new DispatchLogRepository( new SchemaHealth() );

		$repo->claim_or_reject( 2, str_repeat( 'f', 64 ), DispatchLogResult::CLAIMED );

		$this->assertNull( $repo->most_recent_handoff_at( 2 ) );

		$repo->update( 2, str_repeat( 'f', 64 ), DispatchLogResult::HANDED_OFF_TO_M01 );

		$this->assertNotNull( $repo->most_recent_handoff_at( 2 ) );
	}

	public function test_stuck_claim_count_only_counts_old_claimed_rows(): void {
		global $wpdb;

		$repo = new DispatchLogRepository( new SchemaHealth() );

		$table = $wpdb->prefix . Migrator::DISPATCH_LOG_TABLE;
		$wpdb->insert(
			$table,
			array(
				'rule_id'       => 3,
				'event_id'      => str_repeat( 'g', 64 ),
				'result'        => 'claimed',
				'dispatched_at' => gmdate( 'Y-m-d H:i:s', time() - ( 40 * MINUTE_IN_SECONDS ) ),
				'updated_at'    => gmdate( 'Y-m-d H:i:s', time() - ( 40 * MINUTE_IN_SECONDS ) ),
			)
		);

		$repo->claim_or_reject( 3, str_repeat( 'h', 64 ), DispatchLogResult::CLAIMED );

		$this->assertSame( 1, $repo->stuck_claim_count( 30 ) );
	}
}
