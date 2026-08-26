<?php
/**
 * Integration tests for the legacy binding preparation boundary.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Tests\Integration\SupportChatAdapter\Migration;

use UniversalTelegram\Conversations\ConversationRepository;
use UniversalTelegram\Conversations\TopicLifecycleState;
use UniversalTelegram\Conversations\VisitorTokenGenerator;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Migration\DeferredUpdateRepository;
use UniversalTelegram\Migration\QuiescenceGate;
use UniversalTelegram\Migration\QuiescenceTransitionRepository;
use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\SupportChatAdapter\ChannelBinding;
use UniversalTelegram\SupportChatAdapter\ChannelBindingRepository;
use UniversalTelegram\SupportChatAdapter\Migration\BindingImportOutcome;
use UniversalTelegram\SupportChatAdapter\Migration\LegacyBindingImportServiceV1;
use WP_UnitTestCase;

/**
 * Support Chat ADR-0009 / this repository's ADR-0041, against real
 * ConversationRepository/ChannelBindingRepository/QuiescenceGate state —
 * proving the status-specific existing-binding rule (§4), the live
 * topic-state re-check (§2), the atomic quiescence lock (§5), and the
 * `prepared`-only, never-`active` write property (§3) with real DB rows,
 * not mocks (both collaborator classes are `final` and cannot be doubled).
 *
 * The WP-CLI-context-rejection gate itself is covered only by the unit
 * suite (`tests/unit/.../LegacyBindingImportServiceV1Test.php`): this
 * repository's integration bootstrap always runs with `WP_CLI` already
 * `true` (the identical, established constraint the sibling
 * `tests/integration/SupportChatAdapter/Migration/LegacyExportServiceV1Test.php`
 * already documents), so the rejection path is not observable here.
 *
 * @covers \UniversalTelegram\SupportChatAdapter\Migration\LegacyBindingImportServiceV1
 */
final class LegacyBindingImportServiceV1Test extends WP_UnitTestCase {

	private SchemaHealth $schema_health;
	private ConversationRepository $conversations;
	private ChannelBindingRepository $bindings;
	private QuiescenceGate $quiescence;
	private LegacyBindingImportServiceV1 $service;

	protected function setUp(): void {
		parent::setUp();

		global $wpdb;
		$state_table = $wpdb->prefix . Migrator::QUIESCENCE_STATE_TABLE;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "UPDATE {$state_table} SET state = 'idle', updated_at = NOW() WHERE id = 1" );

		// A prior test's QuiescenceGate::enter()/confirm() commit (see
		// tearDown() below) can leave rows from an earlier run's process
		// (or, in this file's own case, an interrupted prior invocation)
		// permanently committed on this persistent DB volume, past what
		// WP_UnitTestCase's own rollback can ever remove. Cleaned here too,
		// not only in tearDown(), so the very first test in a run starts
		// from a genuinely empty table regardless of what came before.
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . Migrator::SUPPORT_CHAT_BINDINGS_TABLE ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . Migrator::CONVERSATIONS_TABLE ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$this->schema_health = new SchemaHealth();
		$this->conversations = new ConversationRepository( $this->schema_health, new CredentialVault(), new VisitorTokenGenerator() );
		$this->bindings      = new ChannelBindingRepository( $this->schema_health );
		$this->quiescence    = new QuiescenceGate(
			$this->schema_health,
			new DeferredUpdateRepository( $this->schema_health, new CredentialVault() ),
			new QuiescenceTransitionRepository()
		);
		$this->service       = new LegacyBindingImportServiceV1( $this->conversations, $this->bindings, $this->quiescence, $this->schema_health );
	}

	/**
	 * QuiescenceGate::enter()/confirm()'s own CAS transitions each commit
	 * their own short transaction, which — on WP_UnitTestCase's shared
	 * connection — also commits whatever this test itself inserted earlier
	 * in the same method, past WP_UnitTestCase's own per-test rollback
	 * (the identical hazard QuiescenceGateTest's and
	 * QuiescenceSupportChatNonInterferenceTest's own tearDown() methods
	 * already document and clean up for their own tables). Cleaned
	 * explicitly here so a conversation or binding row from one test never
	 * leaks into the next and collides with its UNIQUE constraints.
	 */
	protected function tearDown(): void {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . Migrator::SUPPORT_CHAT_BINDINGS_TABLE ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . Migrator::CONVERSATIONS_TABLE ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		parent::tearDown();
	}

	/**
	 * A ready-to-bind legacy conversation: created topic, active lifecycle.
	 */
	private function seed_bindable_conversation( int $bot_id = 5, int $destination_id = 50, int $telegram_topic_id = 500 ): int {
		$conversation = $this->conversations->create( wp_generate_uuid4(), 'secret-hash-' . wp_generate_uuid4(), $bot_id, null );
		$this->assertNotNull( $conversation );

		$this->conversations->mark_topic_created( $conversation->id(), $telegram_topic_id, $destination_id );

		return $conversation->id();
	}

	/**
	 * @return array{source_conversation_id:int, bot_id:int, destination_id:int, telegram_topic_id:int, support_conversation_uuid:string}
	 */
	private function candidate( int $source_conversation_id, int $bot_id, int $destination_id, int $telegram_topic_id, ?string $support_conversation_uuid = null ): array {
		return array(
			'source_conversation_id'    => $source_conversation_id,
			'bot_id'                    => $bot_id,
			'destination_id'            => $destination_id,
			'telegram_topic_id'         => $telegram_topic_id,
			'support_conversation_uuid' => $support_conversation_uuid ?? wp_generate_uuid4(),
		);
	}

	public function test_creates_a_prepared_binding_never_active(): void {
		$id = $this->seed_bindable_conversation( 5, 50, 500 );
		$this->quiescence->enter();
		$this->quiescence->confirm();

		$candidate = $this->candidate( $id, 5, 50, 500 );
		$result    = $this->service->import_batch( array( $candidate ) )[0];

		$this->assertSame( BindingImportOutcome::CREATED, $result['outcome'] );
		$this->assertNotNull( $result['binding_uuid'] );

		$binding = $this->bindings->find_by_uuid( $result['binding_uuid'] );
		$this->assertNotNull( $binding );
		$this->assertSame( ChannelBinding::STATUS_PREPARED, $binding->status() );
		$this->assertNotSame( ChannelBinding::STATUS_ACTIVE, $binding->status() );
	}

	public function test_rerun_against_own_prepared_binding_is_idempotent_success(): void {
		$id = $this->seed_bindable_conversation( 5, 50, 500 );
		$this->quiescence->enter();
		$this->quiescence->confirm();

		$candidate = $this->candidate( $id, 5, 50, 500 );
		$first     = $this->service->import_batch( array( $candidate ) )[0];
		$this->assertSame( BindingImportOutcome::CREATED, $first['outcome'] );

		$second = $this->service->import_batch( array( $candidate ) )[0];
		$this->assertSame( BindingImportOutcome::SKIP_ALREADY_BOUND, $second['outcome'] );
		$this->assertNull( $second['binding_uuid'] );

		global $wpdb;
		$table = $wpdb->prefix . Migrator::SUPPORT_CHAT_BINDINGS_TABLE;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		$this->assertSame( 1, $count, 'A rerun must never create a second binding row.' );
	}

	/**
	 * The core correctness property this ADR adds: a matching existing
	 * `active` binding is never treated as this boundary's own prior
	 * success — it is a distinct, elevated-priority conflict.
	 */
	public function test_matching_active_binding_is_a_conflict_never_idempotent_success(): void {
		$id                        = $this->seed_bindable_conversation( 5, 50, 500 );
		$support_conversation_uuid = wp_generate_uuid4();

		$existing = $this->bindings->create( wp_generate_uuid4(), $support_conversation_uuid, 'ensure-key-active', 5, 50, 500, ChannelBinding::STATUS_ACTIVE );
		$this->assertNotNull( $existing );

		$this->quiescence->enter();
		$this->quiescence->confirm();

		$candidate = $this->candidate( $id, 5, 50, 500, $support_conversation_uuid );
		$result    = $this->service->import_batch( array( $candidate ) )[0];

		$this->assertSame( BindingImportOutcome::CONFLICT_EXISTING_ACTIVE, $result['outcome'] );
		$this->assertNull( $result['binding_uuid'] );

		global $wpdb;
		$table = $wpdb->prefix . Migrator::SUPPORT_CHAT_BINDINGS_TABLE;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		$this->assertSame( 1, $count, 'Must never write a second binding row alongside an existing active one.' );
	}

	public function test_matching_unavailable_binding_is_status_unresolved_conflict(): void {
		$id                        = $this->seed_bindable_conversation( 5, 50, 500 );
		$support_conversation_uuid = wp_generate_uuid4();

		$this->bindings->create( wp_generate_uuid4(), $support_conversation_uuid, 'ensure-key-unavail', 5, 50, 500, ChannelBinding::STATUS_UNAVAILABLE );

		$this->quiescence->enter();
		$this->quiescence->confirm();

		$candidate = $this->candidate( $id, 5, 50, 500, $support_conversation_uuid );
		$result    = $this->service->import_batch( array( $candidate ) )[0];

		$this->assertSame( BindingImportOutcome::CONFLICT_EXISTING_STATUS_UNRESOLVED, $result['outcome'] );
	}

	public function test_mismatched_existing_binding_is_a_conflict(): void {
		$id = $this->seed_bindable_conversation( 5, 50, 500 );

		// A pre-existing binding for the same (bot_id, telegram_topic_id) pointing at a different Support Chat conversation.
		$this->bindings->create( wp_generate_uuid4(), wp_generate_uuid4(), 'ensure-key-mismatch', 5, 50, 500, ChannelBinding::STATUS_PREPARED );

		$this->quiescence->enter();
		$this->quiescence->confirm();

		$candidate = $this->candidate( $id, 5, 50, 500 ); // A different support_conversation_uuid.
		$result    = $this->service->import_batch( array( $candidate ) )[0];

		$this->assertSame( BindingImportOutcome::CONFLICT_EXISTING_MISMATCHED, $result['outcome'] );
	}

	public function test_topic_never_created_is_a_conclusive_skip(): void {
		$conversation = $this->conversations->create( wp_generate_uuid4(), 'secret-hash', 5, null );
		$this->assertNotNull( $conversation );
		// Never mark_topic_created(): topic_creation_state stays 'none'.

		$this->quiescence->enter();
		$this->quiescence->confirm();

		$candidate = $this->candidate( $conversation->id(), 5, 50, 500 );
		$result    = $this->service->import_batch( array( $candidate ) )[0];

		$this->assertSame( BindingImportOutcome::SKIP_TOPIC_STATE_CHANGED, $result['outcome'] );
	}

	/**
	 * Source drift since Phase A's snapshot: the topic lifecycle changed
	 * (e.g. the remote topic was deleted) between migration and this run.
	 */
	public function test_topic_lifecycle_no_longer_active_is_a_conclusive_skip(): void {
		$id = $this->seed_bindable_conversation( 5, 50, 500 );
		$this->conversations->mark_topic_lifecycle( $id, TopicLifecycleState::UNAVAILABLE );

		$this->quiescence->enter();
		$this->quiescence->confirm();

		$candidate = $this->candidate( $id, 5, 50, 500 );
		$result    = $this->service->import_batch( array( $candidate ) )[0];

		$this->assertSame( BindingImportOutcome::SKIP_TOPIC_STATE_CHANGED, $result['outcome'] );
	}

	public function test_not_quiescent_is_retryable_not_terminal(): void {
		$id = $this->seed_bindable_conversation( 5, 50, 500 );
		// Gate left at its default 'idle' state — never entered/confirmed.

		$candidate = $this->candidate( $id, 5, 50, 500 );
		$result    = $this->service->import_batch( array( $candidate ) )[0];

		$this->assertSame( BindingImportOutcome::RETRY_NOT_QUIESCENT, $result['outcome'] );

		global $wpdb;
		$table = $wpdb->prefix . Migrator::SUPPORT_CHAT_BINDINGS_TABLE;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		$this->assertSame( 0, $count, 'A not-quiescent refusal must never write a binding.' );
	}

	/**
	 * A retryable outcome must not permanently block a later, real attempt
	 * once conditions change — the core terminal/retryable correctness
	 * property this design relies on for automatic reruns.
	 */
	public function test_retry_after_quiescence_achieved_succeeds(): void {
		$id        = $this->seed_bindable_conversation( 5, 50, 500 );
		$candidate = $this->candidate( $id, 5, 50, 500 );

		$first = $this->service->import_batch( array( $candidate ) )[0];
		$this->assertSame( BindingImportOutcome::RETRY_NOT_QUIESCENT, $first['outcome'] );

		$this->quiescence->enter();
		$this->quiescence->confirm();

		$second = $this->service->import_batch( array( $candidate ) )[0];
		$this->assertSame( BindingImportOutcome::CREATED, $second['outcome'] );
	}

	public function test_dry_run_writes_nothing_but_reports_would_create(): void {
		$id = $this->seed_bindable_conversation( 5, 50, 500 );
		$this->quiescence->enter();
		$this->quiescence->confirm();

		$candidate = $this->candidate( $id, 5, 50, 500 );
		$result    = $this->service->import_batch( array( $candidate ), true )[0];

		$this->assertSame( BindingImportOutcome::CREATED, $result['outcome'] );
		$this->assertNull( $result['binding_uuid'], 'Dry-run must never return a real binding_uuid.' );

		global $wpdb;
		$table = $wpdb->prefix . Migrator::SUPPORT_CHAT_BINDINGS_TABLE;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		$this->assertSame( 0, $count, 'Dry-run must never commit a write.' );

		// The lock must be released, not left held by the dry-run's rollback:
		// a second, real (non-dry-run) call must still be able to acquire it
		// and succeed.
		$real = $this->service->import_batch( array( $candidate ) )[0];
		$this->assertSame( BindingImportOutcome::CREATED, $real['outcome'] );
		$this->assertNotNull( $real['binding_uuid'] );
	}

	public function test_batch_ceiling_of_100_enforced_server_side(): void {
		$candidates = array();
		for ( $i = 0; $i < 150; $i++ ) {
			$candidates[] = $this->candidate( $i + 1, 5, 50, 500 + $i );
		}

		$results = $this->service->import_batch( $candidates );

		$this->assertCount( 100, $results );
	}
}
