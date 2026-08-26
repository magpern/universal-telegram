<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Migration;

use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Migration\DeferredUpdateRepository;
use UniversalTelegram\Migration\QuiescenceGate;
use UniversalTelegram\Migration\QuiescenceTransitionRepository;
use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Persistence\SchemaHealth;
use WP_UnitTestCase;

/**
 * ADR-0040 §3's required, permanent regression invariant: it must never
 * be possible for a deferred row to exist with `replayed_at IS NULL`
 * while `state = 'idle'`. Both `decide_webhook_disposition()` and
 * `attempt_replaying_to_idle()` serialize on the identical
 * `SELECT ... FOR UPDATE` lock on Table 1's singleton row, so they cannot
 * interleave.
 *
 * This is proven with a genuine second database connection (`mysqli`,
 * using the same connection parameters `$wpdb` itself uses — there is no
 * existing precedent in this repository for exercising two real
 * concurrent transactions, per the milestone-0 research this ADR was
 * written against) holding the row lock open, uncommitted, while the
 * main connection attempts the competing operation: first proving the
 * second attempt genuinely blocks (a shrunk `innodb_lock_wait_timeout`
 * makes the block observable as a fast, deterministic error rather than
 * a real-time wait), then proving that once unblocked it observes the
 * fully-committed prior write and behaves correctly.
 */
final class QuiescenceRaceInterleavingTest extends WP_UnitTestCase {

	private \mysqli $second_connection;
	private string $state_table;
	private string $deferred_table;

	protected function setUp(): void {
		parent::setUp();

		global $wpdb;
		$this->state_table    = $wpdb->prefix . Migrator::QUIESCENCE_STATE_TABLE;
		$this->deferred_table = $wpdb->prefix . Migrator::QUIESCENCE_DEFERRED_UPDATES_TABLE;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "UPDATE {$this->state_table} SET state = 'replaying', updated_at = NOW() WHERE id = 1" );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$this->deferred_table}" );

		// WP_UnitTestCase wraps this entire test in one open transaction on
		// $wpdb's own connection; the UPDATE above would otherwise hold
		// row id=1's lock for the whole test, starving the genuinely
		// separate second connection this test depends on before the
		// scenario it is testing even begins. Committed explicitly here —
		// this test manages its own cleanup for every table it touches, so
		// it does not depend on WP_UnitTestCase's rollback.
		$wpdb->query( 'COMMIT' );
		$wpdb->query( 'START TRANSACTION' );

		// A genuinely second, independent database connection is the whole
		// point of this test — $wpdb is a single shared connection and
		// cannot exercise two real concurrent transactions against itself.
		$this->second_connection = new \mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME ); // phpcs:ignore WordPress.DB.RestrictedClasses.mysql__mysqli
	}

	protected function tearDown(): void {
		$this->second_connection->query( 'ROLLBACK' );
		$this->second_connection->close();

		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "UPDATE {$this->state_table} SET state = 'idle', updated_at = NOW() WHERE id = 1" );
		$wpdb->query( 'SET SESSION innodb_lock_wait_timeout = 50' );
		$wpdb->query( 'COMMIT' );
		$wpdb->query( 'START TRANSACTION' );

		parent::tearDown();
	}

	public function test_a_concurrent_webhook_buffer_transaction_blocks_the_replaying_to_idle_lock_and_is_correctly_observed_once_committed(): void {
		global $wpdb;

		// The second connection simulates decide_webhook_disposition()'s
		// own critical section: lock the row, then (since state !== idle)
		// buffer a row — but hold the transaction open, uncommitted.
		$this->second_connection->query( 'START TRANSACTION' );
		$locked = $this->second_connection->query( "SELECT state FROM {$this->state_table} WHERE id = 1 FOR UPDATE" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$this->assertNotFalse( $locked );

		$inserted = $this->second_connection->query(
			"INSERT INTO {$this->deferred_table} (bot_id, update_id, update_type, payload_ciphertext, received_at) VALUES (1, 1, 'message', 'ciphertext', NOW())" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		);
		$this->assertNotFalse( $inserted );

		// The main connection now attempts the identical row lock — this
		// must genuinely block behind the second connection's still-open
		// transaction. A short lock-wait timeout turns that real block
		// into a fast, deterministic, observable failure instead of a
		// long real-time wait.
		$wpdb->query( 'SET SESSION innodb_lock_wait_timeout = 1' );
		$suppressed = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$blocked_attempt = $wpdb->query( "SELECT state FROM {$this->state_table} WHERE id = 1 FOR UPDATE" );
		$lock_error      = (string) $wpdb->last_error;
		$wpdb->suppress_errors( $suppressed );

		$this->assertFalse( $blocked_attempt, 'The main connection must genuinely block behind, and then time out waiting for, the second connection\'s held lock.' );
		$this->assertStringContainsStringIgnoringCase( 'lock wait timeout', $lock_error );

		// Release the lock the way decide_webhook_disposition() itself
		// would: commit.
		$this->second_connection->query( 'COMMIT' );
		$wpdb->query( 'SET SESSION innodb_lock_wait_timeout = 50' );

		// The real attempt_replaying_to_idle() now proceeds and must
		// observe the fully-committed row: it must never strand it by
		// transitioning to idle while replayed_at IS NULL.
		$schema_health = new SchemaHealth();
		$gate          = new QuiescenceGate(
			$schema_health,
			new DeferredUpdateRepository( $schema_health, new CredentialVault() ),
			new QuiescenceTransitionRepository()
		);

		$result = $gate->attempt_replaying_to_idle();

		$this->assertFalse( $result['success'] );
		$this->assertSame( 1, $result['remaining'] );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$state = $wpdb->get_var( "SELECT state FROM {$this->state_table} WHERE id = 1" );
		$this->assertNotSame( 'idle', $state, 'No unreplayed row may ever coexist with state = idle.' );
	}

	public function test_a_concurrent_replaying_to_idle_transaction_blocks_the_webhook_buffer_lock_and_is_correctly_observed_once_committed(): void {
		global $wpdb;

		// The second connection simulates attempt_replaying_to_idle()'s
		// own critical section with an already-empty backlog: lock the
		// row, re-count (zero), and CAS to idle — but hold the
		// transaction open, uncommitted.
		$this->second_connection->query( 'START TRANSACTION' );
		$locked = $this->second_connection->query( "SELECT state, token FROM {$this->state_table} WHERE id = 1 FOR UPDATE" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$this->assertNotFalse( $locked );

		$new_token = wp_generate_uuid4();
		$updated   = $this->second_connection->query(
			"UPDATE {$this->state_table} SET state = 'idle', token = '{$new_token}', exited_at = NOW(), updated_at = NOW() WHERE id = 1" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		);
		$this->assertNotFalse( $updated );

		// The main connection's webhook-disposition attempt must block
		// behind this still-open transaction too.
		$wpdb->query( 'SET SESSION innodb_lock_wait_timeout = 1' );
		$suppressed = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$blocked_attempt = $wpdb->query( "SELECT state FROM {$this->state_table} WHERE id = 1 FOR UPDATE" );
		$lock_error      = (string) $wpdb->last_error;
		$wpdb->suppress_errors( $suppressed );

		$this->assertFalse( $blocked_attempt );
		$this->assertStringContainsStringIgnoringCase( 'lock wait timeout', $lock_error );

		$this->second_connection->query( 'COMMIT' );
		$wpdb->query( 'SET SESSION innodb_lock_wait_timeout = 50' );

		// The real decide_webhook_disposition() now proceeds and must
		// observe the fully-committed idle state: it must process live,
		// never buffer a genuinely-current update behind a stale read.
		$schema_health = new SchemaHealth();
		$gate          = new QuiescenceGate(
			$schema_health,
			new DeferredUpdateRepository( $schema_health, new CredentialVault() ),
			new QuiescenceTransitionRepository()
		);

		$disposition = $gate->decide_webhook_disposition( 1, 2, 'message', array( 'update_id' => 2 ) );

		$this->assertSame( 'process', $disposition );
		$this->assertSame( 0, $gate->deferred_update_backlog_count() );
	}
}
