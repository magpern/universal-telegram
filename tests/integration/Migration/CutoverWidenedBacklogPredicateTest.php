<?php
/**
 * Integration tests for the widened `replaying → idle` backlog predicate.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Tests\Integration\Migration;

use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Migration\CutoverIncidentReason;
use UniversalTelegram\Migration\DeferredUpdateRepository;
use UniversalTelegram\Migration\QuiescenceGate;
use UniversalTelegram\Migration\QuiescenceTransitionRepository;
use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Persistence\SchemaHealth;
use WP_UnitTestCase;

/**
 * ADR-0042 §3: `attempt_replaying_to_idle()`'s final CAS must observe
 * a row resolved by any of `replayed_at`, `handed_off_at`, or
 * `incident_resolved_at` as no longer blocking — and must still refuse
 * while any row has none of the three set, including an unresolved
 * incident (which, unlike a merely-unattempted row, will never resolve
 * itself without an operator action).
 */
final class CutoverWidenedBacklogPredicateTest extends WP_UnitTestCase {

	private DeferredUpdateRepository $deferred;
	private QuiescenceGate $gate;

	protected function setUp(): void {
		parent::setUp();

		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . Migrator::QUIESCENCE_DEFERRED_UPDATES_TABLE ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$state_table = $wpdb->prefix . Migrator::QUIESCENCE_STATE_TABLE;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "UPDATE {$state_table} SET state = 'replaying', updated_at = NOW() WHERE id = 1" );

		$schema_health  = new SchemaHealth();
		$this->deferred = new DeferredUpdateRepository( $schema_health, new CredentialVault() );
		$this->gate     = new QuiescenceGate( $schema_health, $this->deferred, new QuiescenceTransitionRepository() );
	}

	protected function tearDown(): void {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . Migrator::QUIESCENCE_DEFERRED_UPDATES_TABLE ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		parent::tearDown();
	}

	public function test_idle_transition_succeeds_when_every_row_resolved_by_any_of_the_three_columns(): void {
		$this->deferred->buffer( 1, 1, 'message', array( 'update_id' => 1 ) );
		$this->deferred->buffer( 1, 2, 'message', array( 'update_id' => 2 ) );
		$this->deferred->buffer( 1, 3, 'message', array( 'update_id' => 3 ) );

		global $wpdb;
		$table = $wpdb->prefix . Migrator::QUIESCENCE_DEFERRED_UPDATES_TABLE;
		$ids   = $wpdb->get_col( "SELECT id FROM {$table} ORDER BY id ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$this->deferred->mark_replayed( (int) $ids[0] );
		$this->deferred->mark_handed_off( (int) $ids[1] );
		$this->deferred->record_incident( (int) $ids[2], CutoverIncidentReason::UNSUPPORTED_COMMAND );
		$this->deferred->resolve_incident_acknowledged( (int) $ids[2], 'po-decision-2026-08-27-01' );

		$this->assertSame( 0, $this->deferred->unresolved_backlog_count() );

		$attempt = $this->gate->attempt_replaying_to_idle();

		$this->assertTrue( $attempt['success'] );
		$this->assertSame( 0, $attempt['remaining'] );
	}

	public function test_idle_transition_refuses_while_an_incident_remains_unresolved(): void {
		$this->deferred->buffer( 1, 1, 'message', array( 'update_id' => 1 ) );

		global $wpdb;
		$table = $wpdb->prefix . Migrator::QUIESCENCE_DEFERRED_UPDATES_TABLE;
		$id    = (int) $wpdb->get_var( "SELECT id FROM {$table} LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$this->deferred->record_incident( $id, CutoverIncidentReason::DECRYPT_FAILED );
		// Deliberately never resolved.

		$this->assertSame( 1, $this->deferred->unresolved_backlog_count() );

		$attempt = $this->gate->attempt_replaying_to_idle();

		$this->assertFalse( $attempt['success'], 'An unresolved incident must structurally block replaying → idle.' );
		$this->assertSame( 1, $attempt['remaining'] );
	}

	public function test_idle_transition_refuses_while_a_row_has_none_of_the_three_columns_set(): void {
		$this->deferred->buffer( 1, 1, 'message', array( 'update_id' => 1 ) );
		// No mark_replayed / mark_handed_off / incident of any kind.

		$attempt = $this->gate->attempt_replaying_to_idle();

		$this->assertFalse( $attempt['success'] );
		$this->assertSame( 1, $attempt['remaining'] );
	}
}
