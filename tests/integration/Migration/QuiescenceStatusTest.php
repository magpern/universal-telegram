<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Migration;

use UniversalTelegram\Core\Plugin;
use UniversalTelegram\Migration\QuiescenceStatus;
use UniversalTelegram\Persistence\MigrationLock;
use UniversalTelegram\Persistence\Migrator;
use WP_UnitTestCase;

/**
 * ADR-0040 §8: `Core\Plugin::quiescence_status()` is the frozen, in-process,
 * no-REST cross-plugin signal Support Chat's `QuiescenceStateProvider`
 * implementation depends on. `is_quiescent` requires both
 * `state === 'quiescent'` AND an empty deferred-update backlog, and can
 * become false again purely because a new webhook update arrived and was
 * buffered — without any explicit state transition.
 */
final class QuiescenceStatusTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();

		( new Migrator( new MigrationLock() ) )->maybe_migrate();

		global $wpdb;
		$wpdb->db_connect( true );
		$wpdb->query( 'UPDATE ' . $wpdb->prefix . Migrator::QUIESCENCE_STATE_TABLE . " SET state = 'idle', updated_at = NOW() WHERE id = 1" );
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . Migrator::QUIESCENCE_DEFERRED_UPDATES_TABLE );
	}

	protected function tearDown(): void {
		global $wpdb;
		$wpdb->query( 'UPDATE ' . $wpdb->prefix . Migrator::QUIESCENCE_STATE_TABLE . " SET state = 'idle', updated_at = NOW() WHERE id = 1" );
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . Migrator::QUIESCENCE_DEFERRED_UPDATES_TABLE );
		parent::tearDown();
	}

	public function test_accessor_returns_the_frozen_shape(): void {
		$status = Plugin::instance()->quiescence_status();

		$this->assertInstanceOf( QuiescenceStatus::class, $status );
		$this->assertIsBool( $status->is_quiescent );
	}

	public function test_is_quiescent_is_false_while_idle(): void {
		$status = Plugin::instance()->quiescence_status();

		$this->assertFalse( $status->is_quiescent );
		$this->assertNull( $status->since );
	}

	public function test_is_quiescent_is_true_while_quiescent_with_an_empty_backlog(): void {
		global $wpdb;
		$wpdb->query( 'UPDATE ' . $wpdb->prefix . Migrator::QUIESCENCE_STATE_TABLE . " SET state = 'quiescent', entered_quiescent_at = NOW() WHERE id = 1" );

		$status = Plugin::instance()->quiescence_status();

		$this->assertTrue( $status->is_quiescent );
		$this->assertInstanceOf( \DateTimeImmutable::class, $status->since );
	}

	public function test_is_quiescent_flips_false_the_instant_a_row_is_buffered_without_any_explicit_state_transition(): void {
		global $wpdb;
		$wpdb->query( 'UPDATE ' . $wpdb->prefix . Migrator::QUIESCENCE_STATE_TABLE . " SET state = 'quiescent', entered_quiescent_at = NOW() WHERE id = 1" );

		$this->assertTrue( Plugin::instance()->quiescence_status()->is_quiescent );

		$deferred_table = $wpdb->prefix . Migrator::QUIESCENCE_DEFERRED_UPDATES_TABLE;
		$wpdb->insert(
			$deferred_table,
			array(
				'bot_id'             => 1,
				'update_id'          => 1,
				'update_type'        => 'message',
				'payload_ciphertext' => 'irrelevant',
				'received_at'        => current_time( 'mysql', true ),
			)
		);

		$status = Plugin::instance()->quiescence_status();
		$this->assertFalse( $status->is_quiescent, 'state is still quiescent, but a genuinely new update is now buffered and unresolved.' );

		// True again once that row is marked replayed.
		$wpdb->update( $deferred_table, array( 'replayed_at' => current_time( 'mysql', true ) ), array( 'bot_id' => 1, 'update_id' => 1 ) );
		$this->assertTrue( Plugin::instance()->quiescence_status()->is_quiescent );
	}
}
