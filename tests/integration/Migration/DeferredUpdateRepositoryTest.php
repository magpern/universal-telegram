<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Migration;

use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Migration\DeferredUpdateRepository;
use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Persistence\SchemaHealth;
use WP_UnitTestCase;

/**
 * ADR-0040 §3: replay ordering is deterministic per bot — grouped by
 * bot_id, ordered by Telegram's own update_id ascending within each bot,
 * with the table's own auto-increment id as a stable tie-breaker. This is
 * the layer `Migration\Cli\QuiescenceCommand::replay_deferred_updates()`
 * relies on for its own ordering guarantee.
 */
final class DeferredUpdateRepositoryTest extends WP_UnitTestCase {

	private DeferredUpdateRepository $repository;

	protected function setUp(): void {
		parent::setUp();

		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . Migrator::QUIESCENCE_DEFERRED_UPDATES_TABLE );

		$schema_health    = new SchemaHealth();
		$this->repository = new DeferredUpdateRepository( $schema_health, new CredentialVault() );
	}

	public function test_unreplayed_rows_are_grouped_by_bot_and_ordered_by_update_id_ascending(): void {
		// Deliberately buffered out of order.
		$this->repository->buffer( 2, 50, 'message', array( 'update_id' => 50 ) );
		$this->repository->buffer( 1, 30, 'message', array( 'update_id' => 30 ) );
		$this->repository->buffer( 1, 10, 'message', array( 'update_id' => 10 ) );
		$this->repository->buffer( 2, 20, 'message', array( 'update_id' => 20 ) );

		$grouped = $this->repository->unreplayed_grouped_by_bot();

		$this->assertSame( array( 10, 30 ), array_map( static fn( $r ) => $r->update_id(), $grouped[1] ) );
		$this->assertSame( array( 20, 50 ), array_map( static fn( $r ) => $r->update_id(), $grouped[2] ) );
	}

	public function test_mark_replayed_removes_a_row_from_the_unreplayed_grouping_and_backlog_count(): void {
		$this->repository->buffer( 1, 1, 'message', array( 'update_id' => 1 ) );
		$this->repository->buffer( 1, 2, 'message', array( 'update_id' => 2 ) );

		$grouped = $this->repository->unreplayed_grouped_by_bot();
		$this->assertSame( 2, $this->repository->backlog_count() );

		$this->repository->mark_replayed( $grouped[1][0]->id() );

		$this->assertSame( 1, $this->repository->backlog_count() );
		$remaining = $this->repository->unreplayed_grouped_by_bot();
		$this->assertSame( array( 2 ), array_map( static fn( $r ) => $r->update_id(), $remaining[1] ) );
	}

	public function test_decrypt_payload_recovers_the_original_buffered_payload(): void {
		$this->repository->buffer( 5, 900, 'message', array( 'update_id' => 900, 'message' => array( 'text' => 'hello' ) ) );

		$grouped = $this->repository->unreplayed_grouped_by_bot();
		$record  = $grouped[5][0];

		$payload = $this->repository->decrypt_payload( $record );

		$this->assertNotNull( $payload );
		$this->assertSame( 900, $payload['update_id'] );
		$this->assertSame( 'hello', $payload['message']['text'] );
	}

	public function test_delete_replayed_older_than_never_touches_an_unreplayed_row(): void {
		$this->repository->buffer( 1, 1, 'message', array( 'update_id' => 1 ) );

		global $wpdb;
		$table = $wpdb->prefix . Migrator::QUIESCENCE_DEFERRED_UPDATES_TABLE;
		$wpdb->update( $table, array( 'received_at' => gmdate( 'Y-m-d H:i:s', time() - ( 40 * DAY_IN_SECONDS ) ) ), array( 'bot_id' => 1, 'update_id' => 1 ) );

		$deleted = $this->repository->delete_replayed_older_than( 30 );

		$this->assertSame( 0, $deleted );
		$this->assertTrue( $this->repository->exists( 1, 1 ) );
	}
}
