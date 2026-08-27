<?php
/**
 * Integration tests for the SC-M03 final-cutover activation saga.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Tests\Integration\Migration;

use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Migration\CutoverActivationService;
use UniversalTelegram\Migration\CutoverRunRepository;
use UniversalTelegram\Migration\CutoverState;
use UniversalTelegram\Migration\DeferredUpdateRepository;
use UniversalTelegram\Migration\QuiescenceGate;
use UniversalTelegram\Migration\QuiescenceTransitionRepository;
use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\SupportChatAdapter\ChannelBinding;
use UniversalTelegram\SupportChatAdapter\ChannelBindingRepository;
use WP_UnitTestCase;

/**
 * ADR-0042 §2, against real `ChannelBindingRepository`/`QuiescenceGate`/
 * `CutoverRunRepository` state (all `final` and not mockable). Proves: whole-
 * cohort preflight refuses the entire cohort on one ineligible candidate;
 * a fully-eligible cohort activates every candidate; a commit-phase failure
 * triggers compensation of every already-activated candidate in the same
 * run, with `cas_version` ending at exactly pre-run+2 (never restored) and
 * the binding's routing-relevant `status` back at `prepared`.
 *
 * Reuses the identical `self::unique_id()`/fresh-container-collision-
 * avoidance pattern `LegacyBindingImportServiceV1Test` already established
 * (`with_quiescence_lock()`'s real COMMIT breaks `WP_UnitTestCase`'s
 * savepoint chain) — every fixture identifier here is drawn from the same
 * kind of monotonically-increasing, out-of-band counter, never a small
 * reused literal.
 */
final class CutoverActivationServiceTest extends WP_UnitTestCase {

	private SchemaHealth $schema_health;
	private ChannelBindingRepository $bindings;
	private QuiescenceGate $quiescence;
	private CutoverRunRepository $runs;
	private CutoverActivationService $activation;

	private static int $next_unique_id = 910000000;

	protected function setUp(): void {
		parent::setUp();

		global $wpdb;
		$state_table = $wpdb->prefix . Migrator::QUIESCENCE_STATE_TABLE;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "UPDATE {$state_table} SET state = 'idle', updated_at = NOW() WHERE id = 1" );

		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . Migrator::SUPPORT_CHAT_BINDINGS_TABLE ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . Migrator::CUTOVER_RUNS_TABLE ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . Migrator::CUTOVER_TRANSITIONS_TABLE ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . Migrator::CUTOVER_ACTIVATION_AUDIT_TABLE ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$this->schema_health = new SchemaHealth();
		$this->bindings      = new ChannelBindingRepository( $this->schema_health );
		$this->quiescence    = new QuiescenceGate(
			$this->schema_health,
			new DeferredUpdateRepository( $this->schema_health, new CredentialVault() ),
			new QuiescenceTransitionRepository()
		);
		$this->runs          = new CutoverRunRepository( $this->schema_health );
		$this->activation    = new CutoverActivationService( $this->bindings, $this->quiescence, $this->runs );
	}

	protected function tearDown(): void {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . Migrator::SUPPORT_CHAT_BINDINGS_TABLE ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . Migrator::CUTOVER_RUNS_TABLE ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . Migrator::CUTOVER_TRANSITIONS_TABLE ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . Migrator::CUTOVER_ACTIVATION_AUDIT_TABLE ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		parent::tearDown();
	}

	private static function unique_id(): int {
		return self::$next_unique_id++;
	}

	private function seed_prepared_binding(): string {
		$conversation_uuid = wp_generate_uuid4();
		$binding           = $this->bindings->create(
			wp_generate_uuid4(),
			$conversation_uuid,
			'ensure-key-' . $conversation_uuid,
			self::unique_id(),
			self::unique_id(),
			self::unique_id(),
			ChannelBinding::STATUS_PREPARED
		);
		$this->assertNotNull( $binding );

		return $conversation_uuid;
	}

	public function test_preflight_all_eligible(): void {
		$a = $this->seed_prepared_binding();
		$b = $this->seed_prepared_binding();

		$result = $this->activation->preflight( array( $a, $b ) );

		$this->assertTrue( $result['eligible'] );
		$this->assertCount( 2, $result['results'] );
		foreach ( $result['results'] as $r ) {
			$this->assertTrue( $r['eligible'] );
		}
	}

	public function test_preflight_refuses_whole_cohort_on_one_ineligible_candidate(): void {
		$eligible   = $this->seed_prepared_binding();
		$ineligible = wp_generate_uuid4(); // No binding at all.

		$result = $this->activation->preflight( array( $eligible, $ineligible ) );

		$this->assertFalse( $result['eligible'] );
		$reasons = array_column( $result['results'], 'reason', 'conversation_uuid' );
		$this->assertNull( $reasons[ $eligible ] );
		$this->assertSame( 'no_prepared_binding', $reasons[ $ineligible ] );
	}

	public function test_preflight_flags_already_active_as_ineligible(): void {
		$conversation_uuid = wp_generate_uuid4();
		$this->bindings->create(
			wp_generate_uuid4(),
			$conversation_uuid,
			'ensure-key-active-' . $conversation_uuid,
			self::unique_id(),
			self::unique_id(),
			self::unique_id(),
			ChannelBinding::STATUS_ACTIVE
		);

		$result = $this->activation->preflight( array( $conversation_uuid ) );

		$this->assertFalse( $result['eligible'] );
		$this->assertSame( 'not_prepared_status_active', $result['results'][0]['reason'] );
	}

	public function test_full_cohort_activates_every_candidate(): void {
		$a = $this->seed_prepared_binding();
		$b = $this->seed_prepared_binding();

		$preflight = $this->activation->preflight( array( $a, $b ) );
		$this->assertTrue( $preflight['eligible'] );

		$run = $this->runs->create_prepared( 2 );
		$this->assertNotNull( $run );
		$this->runs->transition_to_activating( $run->id(), null );

		$this->quiescence->enter();
		$this->quiescence->confirm();

		$binding_uuids = array_column( $preflight['results'], 'binding_uuid' );
		$result        = $this->activation->commit( $run->id(), $binding_uuids );

		$this->assertTrue( $result['success'] );
		$this->assertCount( 2, $result['activated'] );

		foreach ( $binding_uuids as $binding_uuid ) {
			$binding = $this->bindings->find_by_uuid( $binding_uuid );
			$this->assertNotNull( $binding );
			$this->assertSame( ChannelBinding::STATUS_ACTIVE, $binding->status() );
			$this->assertTrue( $binding->is_active() );
			$this->assertSame( 2, $binding->cas_version(), 'A freshly created binding starts at cas_version=1; one activation increments it to 2.' );
		}

		$audit = $this->runs->activation_audit_for_run( $run->id() );
		$this->assertCount( 2, $audit );
		foreach ( $audit as $row ) {
			$this->assertSame( 'activate', $row['action'] );
			$this->assertSame( 1, $row['from_cas'] );
			$this->assertSame( 2, $row['to_cas'] );
		}
	}

	/**
	 * The core correctness property this ADR corrects: a commit-phase
	 * failure compensates every already-activated candidate back to
	 * `prepared`, with `cas_version` ending at exactly pre-run+2 — never
	 * restored to its pre-run value of 1.
	 */
	public function test_commit_failure_compensates_all_previously_activated_candidates_with_monotonic_cas(): void {
		$a = $this->seed_prepared_binding();
		$b = $this->seed_prepared_binding();

		$preflight     = $this->activation->preflight( array( $a, $b ) );
		$binding_uuids = array_column( $preflight['results'], 'binding_uuid' );

		$run = $this->runs->create_prepared( 2 );
		$this->assertNotNull( $run );
		$this->runs->transition_to_activating( $run->id(), null );

		$this->quiescence->enter();
		$this->quiescence->confirm();

		// Simulate a genuine race: candidate B is externally activated
		// (e.g. by BindingImportCommand --apply, or a concurrent run)
		// between preflight and this saga's own commit-phase turn for it.
		$b_binding = $this->bindings->find_by_uuid( $binding_uuids[1] );
		$this->assertNotNull( $b_binding );
		$this->assertTrue( $this->bindings->activate_prepared( $binding_uuids[1], $b_binding->cas_version() ) );

		$result = $this->activation->commit( $run->id(), $binding_uuids );

		$this->assertFalse( $result['success'] );
		$this->assertSame( $binding_uuids[1], $result['failed_candidate'] );
		$this->assertSame( array( $binding_uuids[0] ), $result['compensated'], 'Only candidate A was actually activated by the saga itself before B failed — only A needs compensation.' );

		$a_binding = $this->bindings->find_by_uuid( $binding_uuids[0] );
		$this->assertNotNull( $a_binding );
		$this->assertSame( ChannelBinding::STATUS_PREPARED, $a_binding->status(), 'Compensated candidate returns to prepared — never routes traffic.' );
		$this->assertFalse( $a_binding->is_active() );
		$this->assertSame( 3, $a_binding->cas_version(), 'Monotonic: 1 (created) -> 2 (activate) -> 3 (compensate). Never restored to 1.' );

		// Candidate B, externally activated outside the saga, is left
		// exactly as the external actor set it — the saga never touches a
		// candidate it did not itself activate.
		$b_binding_after = $this->bindings->find_by_uuid( $binding_uuids[1] );
		$this->assertNotNull( $b_binding_after );
		$this->assertSame( ChannelBinding::STATUS_ACTIVE, $b_binding_after->status() );

		$audit = $this->runs->activation_audit_for_run( $run->id() );
		$this->assertCount( 2, $audit, 'One activate row for A, one compensate row for A — both run-correlated.' );
		$this->assertSame( 'activate', $audit[0]['action'] );
		$this->assertSame( 'compensate', $audit[1]['action'] );
		$this->assertSame( $binding_uuids[0], $audit[0]['binding_uuid'] );
		$this->assertSame( $binding_uuids[0], $audit[1]['binding_uuid'] );
	}

	public function test_run_state_machine_records_run_correlated_transitions(): void {
		$a = $this->seed_prepared_binding();

		$run = $this->runs->create_prepared( 1 );
		$this->assertNotNull( $run );
		$this->assertSame( CutoverState::PREPARED, $run->state() );

		$this->assertTrue( $this->runs->transition_to_activating( $run->id(), null ) );

		$this->quiescence->enter();
		$this->quiescence->confirm();

		$preflight = $this->activation->preflight( array( $a ) );
		$result    = $this->activation->commit( $run->id(), array_column( $preflight['results'], 'binding_uuid' ) );
		$this->assertTrue( $result['success'] );

		$this->assertTrue( $this->runs->transition_to_activated( $run->id(), null, 'wp-cli', 'activated=1' ) );

		$refreshed = $this->runs->find_by_uuid( $run->run_uuid() );
		$this->assertNotNull( $refreshed );
		$this->assertSame( CutoverState::ACTIVATED, $refreshed->state() );

		// Only one run may be open at a time while it has not reached a
		// terminal state — but this run is now `activated`, still "open"
		// (neither complete nor activation_failed), so a second begin must
		// still be refused.
		$this->assertNotNull( $this->runs->find_open() );
		$this->assertNull( $this->runs->create_prepared( 1 ) );

		$this->assertTrue( $this->runs->transition_to_complete( $run->id(), null, 'wp-cli' ) );
		$this->assertNull( $this->runs->find_open(), 'A completed run is no longer open — a new run may now begin.' );
	}
}
