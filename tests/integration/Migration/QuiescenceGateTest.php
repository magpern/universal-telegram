<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Migration;

use ActionScheduler;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Migration\DeferredUpdateRepository;
use UniversalTelegram\Migration\QuiescenceGate;
use UniversalTelegram\Migration\QuiescenceState;
use UniversalTelegram\Migration\QuiescenceTransitionRepository;
use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Queue\Dispatcher;
use UniversalTelegram\Queue\JobEnvelope;
use UniversalTelegram\Queue\WorkerRunner;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;
use UniversalTelegram\Telegram\Configuration\DestinationKind;
use UniversalTelegram\Telegram\Configuration\DestinationRepository;
use WP_UnitTestCase;

/**
 * ADR-0040 §4/§5/§6: the CAS state machine, its audit trail, and every
 * async-work drain-proof query, including the required destination_id-join
 * refinement for telegram_send_message (the non-interference proof).
 */
final class QuiescenceGateTest extends WP_UnitTestCase {

	private QuiescenceGate $gate;
	private SchemaHealth $schema_health;

	protected function setUp(): void {
		parent::setUp();

		global $wpdb;
		$state_table = $wpdb->prefix . Migrator::QUIESCENCE_STATE_TABLE;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "UPDATE {$state_table} SET state = 'idle', updated_at = NOW() WHERE id = 1" );

		$transitions_table = $wpdb->prefix . Migrator::QUIESCENCE_TRANSITIONS_TABLE;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$transitions_table}" );

		$deferred_table = $wpdb->prefix . Migrator::QUIESCENCE_DEFERRED_UPDATES_TABLE;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$deferred_table}" );

		// Action Scheduler's own tables are not wrapped by WP_UnitTestCase's
		// per-test rollback (QueueHealthTest's own established precedent).
		$ids = ActionScheduler::store()->query_actions( array( 'group' => WorkerRunner::GROUP ) );
		foreach ( (array) $ids as $id ) {
			ActionScheduler::store()->delete_action( (int) $id );
		}

		$this->schema_health = new SchemaHealth();
		$this->gate          = new QuiescenceGate(
			$this->schema_health,
			new DeferredUpdateRepository( $this->schema_health, new CredentialVault() ),
			new QuiescenceTransitionRepository()
		);
	}

	/**
	 * Some tests below trigger a real Table 1 CAS, which — like every
	 * QuiescenceGate transition — commits its own short transaction. On
	 * WP_UnitTestCase's shared connection this also commits whatever else
	 * was pending in that same test's outer transaction, including any
	 * Action Scheduler action this test itself enqueued moments earlier —
	 * permanently, past this test's own rollback. Cleaned explicitly here
	 * so such a row never leaks into an unrelated, later-run test file
	 * that assumes an empty group (this is the same class of hazard
	 * documented in WebhookControllerQuiescenceTest's own commit message).
	 */
	protected function tearDown(): void {
		global $wpdb;
		// A raw delete, not the Store API: the CAS commit above can leave
		// this connection's view of Action Scheduler's own store in a
		// state where query_actions()/delete_action() no longer reliably
		// see a row already committed moments earlier on this same
		// connection. A direct DELETE against its own table is unambiguous.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}actionscheduler_actions WHERE hook = %s", WorkerRunner::HOOK ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		parent::tearDown();
	}

	public function test_initial_state_is_idle(): void {
		$this->assertSame( QuiescenceState::IDLE, $this->gate->state() );
		$this->assertTrue( $this->gate->is_idle() );
	}

	public function test_enter_transitions_idle_to_draining_and_records_an_audit_row(): void {
		$succeeded = $this->gate->enter( 'wp-cli', 7 );

		$this->assertTrue( $succeeded );
		$this->assertSame( QuiescenceState::DRAINING, $this->gate->state() );

		global $wpdb;
		$table = $wpdb->prefix . Migrator::QUIESCENCE_TRANSITIONS_TABLE;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( "SELECT * FROM {$table} ORDER BY id DESC LIMIT 1", ARRAY_A );

		$this->assertNotNull( $row );
		$this->assertSame( 'idle', $row['from_state'] );
		$this->assertSame( 'draining', $row['to_state'] );
		$this->assertSame( '7', (string) $row['requested_by'] );
		$this->assertSame( 'wp-cli', $row['requested_via'] );
	}

	public function test_enter_is_idempotent_when_already_draining(): void {
		$this->assertTrue( $this->gate->enter() );
		$token_after_first = $this->gate->token();

		$this->assertTrue( $this->gate->enter() );
		$this->assertSame( $token_after_first, $this->gate->token() );

		global $wpdb;
		$table = $wpdb->prefix . Migrator::QUIESCENCE_TRANSITIONS_TABLE;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

		$this->assertSame( 1, $count );
	}

	public function test_concurrent_enter_resolves_to_exactly_one_transition(): void {
		// Simulates the losing side of a concurrent enter() race: a second
		// gate instance attempts the identical CAS after the first already
		// won it.
		$other = new QuiescenceGate(
			$this->schema_health,
			new DeferredUpdateRepository( $this->schema_health, new CredentialVault() ),
			new QuiescenceTransitionRepository()
		);

		$this->assertTrue( $this->gate->enter() );
		$this->assertTrue( $other->enter() );

		global $wpdb;
		$table = $wpdb->prefix . Migrator::QUIESCENCE_TRANSITIONS_TABLE;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

		$this->assertSame( 1, $count );
	}

	public function test_confirm_fails_when_draining_conditions_are_not_met(): void {
		$this->gate->enter();

		$dispatcher = new Dispatcher( $this->schema_health );
		$dispatcher->enqueue( new JobEnvelope( 'conversation_create_topic', array(), array() ) );

		$result = $this->gate->confirm();

		$this->assertFalse( $result['success'] );
		$this->assertSame( 1, $result['breakdown']['conversation_create_topic'] );
		$this->assertSame( QuiescenceState::DRAINING, $this->gate->state() );
	}

	public function test_confirm_succeeds_when_fully_drained_and_transitions_to_quiescent(): void {
		$this->gate->enter();

		$result = $this->gate->confirm( 'wp-cli', 9 );

		$this->assertTrue( $result['success'] );
		$this->assertSame( QuiescenceState::QUIESCENT, $this->gate->state() );

		global $wpdb;
		$table = $wpdb->prefix . Migrator::QUIESCENCE_STATE_TABLE;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$entered_quiescent_at = $wpdb->get_var( "SELECT entered_quiescent_at FROM {$table} WHERE id = 1" );

		$this->assertNotNull( $entered_quiescent_at );
	}

	public function test_confirm_is_not_possible_from_idle(): void {
		$result = $this->gate->confirm();

		$this->assertFalse( $result['success'] );
		$this->assertSame( QuiescenceState::IDLE, $this->gate->state() );
	}

	public function test_exit_transitions_quiescent_to_replaying(): void {
		$this->gate->enter();
		$this->gate->confirm();

		$this->assertTrue( $this->gate->exit() );
		$this->assertSame( QuiescenceState::REPLAYING, $this->gate->state() );
	}

	public function test_exit_from_draining_also_reaches_replaying_not_idle(): void {
		// The abort-from-draining case (ADR-0040 §6): exit() called before
		// confirm() ever succeeded still goes through replaying.
		$this->gate->enter();

		$this->assertTrue( $this->gate->exit() );
		$this->assertSame( QuiescenceState::REPLAYING, $this->gate->state() );
	}

	public function test_exit_is_a_no_op_from_idle(): void {
		$this->assertFalse( $this->gate->exit() );
		$this->assertSame( QuiescenceState::IDLE, $this->gate->state() );
	}

	public function test_attempt_replaying_to_idle_fails_while_backlog_is_non_empty(): void {
		$this->gate->enter();
		$this->gate->confirm();
		$this->gate->exit();

		global $wpdb;
		$table = $wpdb->prefix . Migrator::QUIESCENCE_DEFERRED_UPDATES_TABLE;
		$wpdb->insert(
			$table,
			array(
				'bot_id'             => 1,
				'update_id'          => 100,
				'update_type'        => 'message',
				'payload_ciphertext' => 'irrelevant',
				'received_at'        => current_time( 'mysql', true ),
			)
		);

		$result = $this->gate->attempt_replaying_to_idle();

		$this->assertFalse( $result['success'] );
		$this->assertSame( 1, $result['remaining'] );
		$this->assertSame( QuiescenceState::REPLAYING, $this->gate->state() );
	}

	public function test_attempt_replaying_to_idle_succeeds_when_backlog_is_empty(): void {
		$this->gate->enter();
		$this->gate->confirm();
		$this->gate->exit();

		$result = $this->gate->attempt_replaying_to_idle( 'wp-cli', 3 );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 0, $result['remaining'] );
		$this->assertSame( QuiescenceState::IDLE, $this->gate->state() );
	}

	public function test_since_is_null_outside_quiescent_and_set_while_quiescent(): void {
		$this->assertNull( $this->gate->since() );

		$this->gate->enter();
		$this->assertNull( $this->gate->since() );

		$this->gate->confirm();
		$this->assertInstanceOf( \DateTimeImmutable::class, $this->gate->since() );

		$this->gate->exit();
		$this->assertNull( $this->gate->since() );
	}

	public function test_drain_breakdown_counts_pending_jobs_of_each_type(): void {
		$dispatcher = new Dispatcher( $this->schema_health );
		$dispatcher->enqueue( new JobEnvelope( 'conversation_create_topic', array(), array() ) );
		$dispatcher->enqueue( new JobEnvelope( 'conversation_delete_topic', array(), array() ) );
		$dispatcher->enqueue( new JobEnvelope( 'conversation_route_outbound', array(), array() ) );
		$dispatcher->enqueue( new JobEnvelope( 'ai_draft_generate', array(), array() ) );

		$breakdown = $this->gate->drain_breakdown();

		$this->assertSame( 1, $breakdown['conversation_create_topic'] );
		$this->assertSame( 1, $breakdown['conversation_delete_topic'] );
		$this->assertSame( 1, $breakdown['conversation_route_outbound'] );
		$this->assertSame( 1, $breakdown['ai_draft_generate'] );
	}

	/**
	 * The required permanent non-interference proof (docs/adr/0040 §5,
	 * Context items 1-2 and §7): a pending telegram_send_message action
	 * whose destination_id belongs to a legacy conversation IS counted;
	 * an otherwise-identical pending action whose destination_id does NOT
	 * belong to any legacy conversation (i.e. a Support Chat channel
	 * binding's own destination) is NEVER counted, exercised while
	 * state = 'quiescent'.
	 */
	public function test_telegram_send_message_drain_count_only_includes_legacy_owned_destinations(): void {
		$schema_health = $this->schema_health;
		$vault         = new CredentialVault();
		$bots          = new BotProfileRepository( $schema_health, $vault );
		$destinations  = new DestinationRepository( $schema_health );

		$bot                 = $bots->create( 'Bot', str_repeat( 'a', 46 ) );
		$legacy_destination  = $destinations->create( $bot->id(), DestinationKind::GROUP, '-1001', null, 'Legacy' );
		$adapter_destination = $destinations->create( $bot->id(), DestinationKind::GROUP, '-1002', null, 'Adapter' );

		global $wpdb;
		$conversations_table = $wpdb->prefix . Migrator::CONVERSATIONS_TABLE;
		$now                 = current_time( 'mysql', true );
		$wpdb->insert(
			$conversations_table,
			array(
				'conversation_uuid'      => wp_generate_uuid4(),
				'bot_id'                 => $bot->id(),
				'destination_id'         => $legacy_destination->id(),
				'status'                 => 'open',
				'topic_creation_state'   => 'none',
				'ai_participation_state' => 'none',
				'consent_state'          => 'unknown',
				'created_at'             => $now,
				'updated_at'             => $now,
			),
			array( '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		$dispatcher = new Dispatcher( $schema_health );
		$dispatcher->enqueue(
			new JobEnvelope(
				'telegram_send_message',
				array(
					'message_uuid'   => wp_generate_uuid4(),
					'bot_id'         => $bot->id(),
					'destination_id' => $legacy_destination->id(),
				),
				array(
					'message_uuid'   => \UniversalTelegram\Privacy\Classification::INTERNAL,
					'bot_id'         => \UniversalTelegram\Privacy\Classification::INTERNAL,
					'destination_id' => \UniversalTelegram\Privacy\Classification::INTERNAL,
				)
			)
		);
		// A pending action of the identical job type/hook/group, whose
		// destination_id is not owned by any legacy conversation — the
		// Support Chat adapter's own delivery traffic.
		$dispatcher->enqueue(
			new JobEnvelope(
				'telegram_send_message',
				array(
					'message_uuid'   => wp_generate_uuid4(),
					'bot_id'         => $bot->id(),
					'destination_id' => $adapter_destination->id(),
				),
				array(
					'message_uuid'   => \UniversalTelegram\Privacy\Classification::INTERNAL,
					'bot_id'         => \UniversalTelegram\Privacy\Classification::INTERNAL,
					'destination_id' => \UniversalTelegram\Privacy\Classification::INTERNAL,
				)
			)
		);

		$this->gate->enter();
		$breakdown_draining = $this->gate->drain_breakdown();
		$this->assertSame( 1, $breakdown_draining['telegram_send_message'] );

		// Exercised at quiescent, per the required test scenario.
		global $wpdb;
		$state_table = $wpdb->prefix . Migrator::QUIESCENCE_STATE_TABLE;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "UPDATE {$state_table} SET state = 'quiescent' WHERE id = 1" );

		$breakdown_quiescent = $this->gate->drain_breakdown();
		$this->assertSame( 1, $breakdown_quiescent['telegram_send_message'], 'Only the legacy-owned destination is counted; the adapter destination never blocks confirm().' );
	}

	public function test_decide_webhook_disposition_processes_live_when_idle(): void {
		$disposition = $this->gate->decide_webhook_disposition( 1, 100, 'message', array( 'update_id' => 100 ) );

		$this->assertSame( 'process', $disposition );
		$this->assertSame( 0, $this->gate->deferred_update_backlog_count() );
	}

	public function test_decide_webhook_disposition_buffers_when_not_idle(): void {
		$this->gate->enter();

		$disposition = $this->gate->decide_webhook_disposition(
			1,
			100,
			'message',
			array(
				'update_id' => 100,
				'secret'    => 'x',
			)
		);

		$this->assertSame( 'buffered', $disposition );
		$this->assertSame( 1, $this->gate->deferred_update_backlog_count() );
	}

	public function test_decide_webhook_disposition_is_idempotent_for_a_duplicate_delivery(): void {
		$this->gate->enter();

		$first  = $this->gate->decide_webhook_disposition( 1, 100, 'message', array( 'update_id' => 100 ) );
		$second = $this->gate->decide_webhook_disposition( 1, 100, 'message', array( 'update_id' => 100 ) );

		$this->assertSame( 'buffered', $first );
		$this->assertSame( 'buffered', $second );
		$this->assertSame( 1, $this->gate->deferred_update_backlog_count(), 'A duplicate delivery must never create a second row.' );
	}

	public function test_buffered_payload_ciphertext_is_never_plaintext_equal_to_the_raw_payload(): void {
		$this->gate->enter();
		$this->gate->decide_webhook_disposition(
			1,
			100,
			'message',
			array(
				'update_id' => 100,
				'message'   => array( 'text' => 'secret text' ),
			)
		);

		global $wpdb;
		$table = $wpdb->prefix . Migrator::QUIESCENCE_DEFERRED_UPDATES_TABLE;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ciphertext = $wpdb->get_var( "SELECT payload_ciphertext FROM {$table} WHERE bot_id = 1 AND update_id = 100" );

		$this->assertNotNull( $ciphertext );
		$this->assertStringNotContainsString( 'secret text', $ciphertext );
	}

	public function test_issue_replay_context_is_null_unless_replaying(): void {
		$this->assertNull( $this->gate->issue_replay_context() );

		$this->gate->enter();
		$this->assertNull( $this->gate->issue_replay_context() );

		$this->gate->confirm();
		$this->assertNull( $this->gate->issue_replay_context() );

		$this->gate->exit();
		$this->assertNotNull( $this->gate->issue_replay_context() );
	}

	public function test_issue_replay_context_token_matches_current_epoch(): void {
		$this->gate->enter();
		$this->gate->confirm();
		$this->gate->exit();

		$context = $this->gate->issue_replay_context();

		$this->assertNotNull( $context );
		$this->assertSame( $this->gate->token(), $context->token() );
		$this->assertTrue( $this->gate->is_valid_replay_context( $context ) );
	}

	public function test_is_valid_replay_context_rejects_a_stale_token(): void {
		$this->gate->enter();
		$this->gate->confirm();
		$this->gate->exit();

		$stale_context = $this->gate->issue_replay_context();

		$this->gate->attempt_replaying_to_idle();
		// A fresh epoch: re-enter and reach replaying again.
		$this->gate->enter();
		$this->gate->confirm();
		$this->gate->exit();

		$this->assertNotNull( $stale_context );
		$this->assertFalse( $this->gate->is_valid_replay_context( $stale_context ), 'A context minted for a prior replaying epoch must not be honored in a later one.' );
	}

	public function test_is_valid_replay_context_rejects_null(): void {
		$this->assertFalse( $this->gate->is_valid_replay_context( null ) );
	}
}
