<?php
/**
 * SC-M03 final-cutover mutual-pairing interoperability suite.
 *
 * Closes the gap both closure records disclosed at merge time
 * (`docs/closure/ut-adapter-m1-final-cutover-closure.md`,
 * `docs/closure/sc-m03-final-cutover-closure.md`): no prior suite drove
 * `CutoverReplayDispatcher` all the way through a real, signed
 * `SupportChatContractClient` call into Support Chat's real, registered
 * REST controller and real `HandoffMapRepository`. This file does exactly
 * that, reusing `InteropTestCase`'s real two-way pairing — never a fake
 * client, a mocked handler, a direct repository write standing in for the
 * wire call, or a stub Contract server.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Tests\Integration\Interop;

use UniversalSupportChat\ChannelContract\HandoffMapRepository as ScHandoffMapRepository;
use UniversalSupportChat\Persistence\Migrator as ScMigrator;
use UniversalSupportChat\Persistence\SchemaHealth as ScSchemaHealth;
use UniversalTelegram\Audit\AuditLogger as UtAuditLogger;
use UniversalTelegram\Conversations\OperatorIdentityRepository;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Migration\CutoverIncidentReason;
use UniversalTelegram\Migration\CutoverReplayDispatcher;
use UniversalTelegram\Migration\DeferredUpdateRecord;
use UniversalTelegram\Migration\DeferredUpdateRepository;
use UniversalTelegram\Persistence\Migrator as UtMigrator;
use UniversalTelegram\Persistence\SchemaHealth as UtSchemaHealth;
use UniversalTelegram\Privacy\Redactor as UtRedactor;
use UniversalTelegram\SupportChatAdapter\ChannelBinding;

/**
 * `ContractOperationDispatcher::dispatch_with_provenance()` performs a real
 * `START TRANSACTION`/`COMMIT` for every provenance-carrying call this
 * class's own tests exercise — every test here — which is the identical
 * class of hazard Support Chat's own
 * `ContractOperationsControllerTest`/`QuiescenceProviderIntegrationTest`
 * already document: a real COMMIT collapses `WP_UnitTestCase`'s
 * savepoint-based per-test isolation for the rest of this PHPUnit process
 * (`phpunit-interop.xml.dist` runs every interop file in one process), so
 * explicit cleanup — run from both `setUp()` (before `parent::setUp()`
 * builds this test's own fresh fixtures) and `tearDown()` (after, ending
 * with an explicit `COMMIT` so the cleanup itself is durable regardless of
 * ambient transaction state) — is required, not optional. No other file in
 * this suite triggers a real commit, so no other file needs this pattern;
 * this class is the first to do so on the Universal Telegram side.
 */
final class CutoverHandoffIntegrationTest extends InteropTestCase {

	private DeferredUpdateRepository $ut_deferred;
	private OperatorIdentityRepository $ut_operator_identities;
	private CutoverReplayDispatcher $dispatcher;
	private ScHandoffMapRepository $sc_handoff_map;

	private static int $next_topic_id  = 700000;
	private static int $next_update_id = 800000;

	protected function setUp(): void {
		$this->clean_tables_committed_by_real_transactions();

		parent::setUp();

		$ut_schema_health = new UtSchemaHealth();

		$this->ut_deferred            = new DeferredUpdateRepository( $ut_schema_health, new CredentialVault() );
		$this->ut_operator_identities = new OperatorIdentityRepository( $ut_schema_health );
		$this->dispatcher             = new CutoverReplayDispatcher(
			$this->ut_operator_identities,
			$this->ut_outbound_client,
			$this->ut_deferred,
			new UtAuditLogger( $ut_schema_health, new UtRedactor() )
		);
		$this->sc_handoff_map         = new ScHandoffMapRepository( new ScSchemaHealth() );
	}

	protected function tearDown(): void {
		$this->clean_tables_committed_by_real_transactions();
		parent::tearDown();
	}

	/**
	 * Deletes every row this class's own tests can create on either side —
	 * both Universal Telegram's and Support Chat's — and finishes with an
	 * explicit `COMMIT`. See this class's own docblock.
	 */
	private function clean_tables_committed_by_real_transactions(): void {
		global $wpdb;

		foreach (
			array(
				UtMigrator::QUIESCENCE_DEFERRED_UPDATES_TABLE,
				UtMigrator::SUPPORT_CHAT_BINDINGS_TABLE,
				UtMigrator::OPERATOR_IDENTITIES_TABLE,
			) as $table_constant
		) {
			$table = $wpdb->prefix . $table_constant;
			$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		}

		foreach (
			array(
				ScMigrator::LEGACY_HANDOFF_MAP_TABLE,
				ScMigrator::CHANNEL_STATUS_TABLE,
				ScMigrator::CONVERSATION_MESSAGES_TABLE,
				ScMigrator::CONVERSATIONS_TABLE,
			) as $table_constant
		) {
			$table = $wpdb->prefix . $table_constant;
			$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'COMMIT' );
	}

	private static function next_topic_id(): int {
		return self::$next_topic_id++;
	}

	private static function next_update_id(): int {
		return self::$next_update_id++;
	}

	/**
	 * Creates a real, active UT binding for a fresh topic pointing at a
	 * real SC conversation, and returns both.
	 *
	 * `CutoverReplayDispatcher`/`SupportChatContractClient` always send
	 * `$binding->binding_uuid()` as `channel_case_ref` (real production
	 * code, `CutoverReplayDispatcher.php:134` etc.), and Support Chat's own
	 * `ContractOperationDispatcher::resolve_conversation()` resolves
	 * `channel_case_ref` by looking it up directly as its own real
	 * `conversation_uuid` (`find_by_uuid()`) — the identical convention
	 * `InteropTestCase::create_sc_conversation()`'s own established callers
	 * (`UtToScOperationsTest`) already rely on, passing the SC conversation
	 * UUID straight through as `channel_case_ref`. This binding's own
	 * `binding_uuid` is therefore deliberately seeded equal to the real SC
	 * conversation UUID, not a separate opaque value — the exact value this
	 * suite's own real dispatch calls must carry for Support Chat to
	 * resolve them to the real conversation this test seeded.
	 *
	 * @return array{topic_id: int, conversation_uuid: string, binding_uuid: string}
	 */
	private function seed_active_binding_and_conversation(): array {
		$conversation_uuid = $this->create_sc_conversation();
		$topic_id          = self::next_topic_id();

		$binding = $this->ut_bindings->create(
			$conversation_uuid,
			$conversation_uuid,
			'interop-cutover-ensure-' . $topic_id,
			$this->bot_id,
			$this->parent_destination->id(),
			$topic_id,
			ChannelBinding::STATUS_ACTIVE
		);
		self::assertNotNull( $binding, 'Failed to seed a real active UT binding.' );

		return array(
			'topic_id'          => $topic_id,
			'conversation_uuid' => $conversation_uuid,
			'binding_uuid'      => $binding->binding_uuid(),
		);
	}

	/**
	 * Buffers a real deferred-update row and returns its full
	 * `DeferredUpdateRecord`, mirroring how a real
	 * `QuiescenceCommand::replay_deferred_updates()` pass would read it
	 * back off the table before dispatching it.
	 */
	private function buffer_and_fetch( int $update_id, string $update_type, array $raw_payload ): DeferredUpdateRecord {
		$ok = $this->ut_deferred->buffer( $this->bot_id, $update_id, $update_type, $raw_payload );
		self::assertTrue( $ok, 'Failed to buffer a real deferred-update row.' );

		global $wpdb;
		$table = $wpdb->prefix . \UniversalTelegram\Persistence\Migrator::QUIESCENCE_DEFERRED_UPDATES_TABLE;
		$id    = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE bot_id = %d AND update_id = %d", $this->bot_id, $update_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		self::assertGreaterThan( 0, $id );

		return new DeferredUpdateRecord( $id, $this->bot_id, $update_id, $update_type, current_time( 'mysql', true ), null );
	}

	private function map_row_count_for( int $update_id ): int {
		global $wpdb;
		$table = $wpdb->prefix . \UniversalSupportChat\Persistence\Migrator::LEGACY_HANDOFF_MAP_TABLE;

		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE bot_id = %d AND update_id = %d", $this->bot_id, $update_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * 1. A deferred operator reply reaches Support Chat through the real
	 * `SupportChatContractClient`, creates the real SC message and exactly
	 * one real handoff-map row, then UT stamps `handed_off_at`.
	 */
	public function test_deferred_operator_reply_handoff_creates_sc_message_and_one_map_row_then_stamps_handed_off_at(): void {
		$seed      = $this->seed_active_binding_and_conversation();
		$sender_id = 424242;
		$this->ut_operator_identities->create( 1, $sender_id, null, 1 );

		$update_id = self::next_update_id();
		$record    = $this->buffer_and_fetch(
			$update_id,
			'message',
			array(
				'message' => array(
					'text' => 'A real reply, replayed after final cutover.',
					'from' => array( 'id' => $sender_id ),
				),
			)
		);
		$bot       = $this->ut_bots->find( $this->bot_id );
		self::assertNotNull( $bot );
		$binding = $this->ut_bindings->find_by_bot_topic( $this->bot_id, $seed['topic_id'] );
		self::assertNotNull( $binding );

		$outcome = $this->dispatcher->dispatch(
			$bot,
			$record,
			$binding,
			array(
				'message' => array(
					'text' => 'A real reply, replayed after final cutover.',
					'from' => array( 'id' => $sender_id ),
				),
			)
		);

		self::assertSame( CutoverReplayDispatcher::OUTCOME_HANDED_OFF, $outcome );

		$conversation = $this->sc_conversations->find_by_uuid( $seed['conversation_uuid'] );
		self::assertNotNull( $conversation );
		$messages = $this->sc_messages->list_for_conversation( $conversation->id() );
		self::assertCount( 1, $messages, 'Expected exactly one real SC message from the real handoff.' );
		self::assertSame( 'A real reply, replayed after final cutover.', $messages[0]->plaintext_body() );

		self::assertSame( 1, $this->map_row_count_for( $update_id ), 'Expected exactly one real SC handoff-map row.' );
		$map_row = $this->sc_handoff_map->find( $this->bot_id, $update_id );
		self::assertNotNull( $map_row );
		self::assertSame( 'message', $map_row['kind'] );
		self::assertSame( $seed['conversation_uuid'], $map_row['channel_case_ref'] );

		$row = $this->ut_deferred->find_by_id( $record->id() );
		self::assertNotNull( $row );
		self::assertNotNull( $row['handed_off_at'], 'UT must stamp handed_off_at only after a real SC success.' );
		self::assertNull( $row['incident_reason'] );
	}

	/**
	 * 2. A retry after Support Chat has really committed but before UT
	 * marks `handed_off_at` (simulated by dispatching the identical
	 * provenance a second time before the first call's own
	 * `mark_handed_off()` — i.e. calling the real client directly, the
	 * same way `CutoverReplayDispatcher::dispatch_message()` itself would
	 * on a re-attempted row) converges safely: no duplicate SC domain
	 * effect, the matching-provenance retry is accepted, and UT eventually
	 * stamps `handed_off_at`.
	 */
	public function test_retry_before_handed_off_at_is_stamped_converges_with_no_duplicate_effect(): void {
		$seed      = $this->seed_active_binding_and_conversation();
		$update_id = self::next_update_id();
		$key       = 'tg-update-' . $this->bot_id . '-' . $update_id;

		// First real call — the SC side genuinely commits (message + map
		// row), standing in for "SC committed but the process died before
		// UT's own mark_handed_off() ran".
		$first = $this->ut_outbound_client->ingest_operator_reply(
			$seed['binding_uuid'],
			$key,
			'Reply that must not be duplicated on retry.',
			1,
			array( 'telegram_update_id' => $update_id ),
			$this->bot_id,
			$update_id
		);
		self::assertTrue( $first['ok'], (string) $first['reason'] );

		self::assertSame( 1, $this->map_row_count_for( $update_id ) );
		$conversation = $this->sc_conversations->find_by_uuid( $seed['conversation_uuid'] );
		self::assertNotNull( $conversation );
		self::assertCount( 1, $this->sc_messages->list_for_conversation( $conversation->id() ) );

		// Retry: identical provenance, identical kind/channel_case_ref,
		// identical idempotency key — a genuine retry, not a conflict.
		$retry = $this->ut_outbound_client->ingest_operator_reply(
			$seed['binding_uuid'],
			$key,
			'Reply that must not be duplicated on retry.',
			1,
			array( 'telegram_update_id' => $update_id ),
			$this->bot_id,
			$update_id
		);
		self::assertTrue( $retry['ok'], (string) $retry['reason'] );

		self::assertSame( 1, $this->map_row_count_for( $update_id ), 'Retry must not create a second real handoff-map row.' );
		self::assertCount( 1, $this->sc_messages->list_for_conversation( $conversation->id() ), 'Retry must not create a second real SC message.' );

		// Only now does UT run the real deferred-update row through the
		// dispatcher (the eventual real replay pass this scenario stands
		// in for) — proving it converges to OUTCOME_HANDED_OFF and stamps
		// handed_off_at, still without a second real SC effect anywhere.
		$this->ut_operator_identities->create( 1, 1, null, 1 );

		$decoded = array(
			'message' => array(
				'text' => 'Reply that must not be duplicated on retry.',
				'from' => array( 'id' => 1 ),
			),
		);
		$record  = $this->buffer_and_fetch( $update_id, 'message', $decoded );
		$bot     = $this->ut_bots->find( $this->bot_id );
		self::assertNotNull( $bot );
		$binding = $this->ut_bindings->find_by_bot_topic( $this->bot_id, $seed['topic_id'] );
		self::assertNotNull( $binding );

		$outcome = $this->dispatcher->dispatch( $bot, $record, $binding, $decoded );
		self::assertSame( CutoverReplayDispatcher::OUTCOME_HANDED_OFF, $outcome );

		$row = $this->ut_deferred->find_by_id( $record->id() );
		self::assertNotNull( $row );
		self::assertNotNull( $row['handed_off_at'] );

		self::assertSame( 1, $this->map_row_count_for( $update_id ) );
		self::assertCount( 1, $this->sc_messages->list_for_conversation( $conversation->id() ) );
	}

	/**
	 * 3. A supported command (`claim`) uses the real path and proves the
	 * correct SC operation is dispatched with provenance.
	 */
	public function test_supported_command_dispatches_the_correct_real_sc_operation_with_provenance(): void {
		$seed      = $this->seed_active_binding_and_conversation();
		$sender_id = 515151;
		$this->ut_operator_identities->create( 42, $sender_id, null, 1 );

		$update_id = self::next_update_id();
		$decoded   = array(
			'message' => array(
				'text'     => '/claim',
				'entities' => array(
					array(
						'type'   => 'bot_command',
						'offset' => 0,
						'length' => 6,
					),
				),
				'from'     => array( 'id' => $sender_id ),
			),
		);
		$record    = $this->buffer_and_fetch( $update_id, 'message', $decoded );

		$bot     = $this->ut_bots->find( $this->bot_id );
		$binding = $this->ut_bindings->find_by_bot_topic( $this->bot_id, $seed['topic_id'] );
		self::assertNotNull( $bot );
		self::assertNotNull( $binding );

		$outcome = $this->dispatcher->dispatch( $bot, $record, $binding, $decoded );

		self::assertSame( CutoverReplayDispatcher::OUTCOME_HANDED_OFF, $outcome );

		$conversation = $this->sc_conversations->find_by_uuid( $seed['conversation_uuid'] );
		self::assertNotNull( $conversation );
		self::assertSame( 42, $conversation->assigned_operator_id(), 'Expected the real SC claim() to have run against the real operator identity mapping.' );

		$map_row = $this->sc_handoff_map->find( $this->bot_id, $update_id );
		self::assertNotNull( $map_row );
		self::assertSame( 'claim', $map_row['kind'] );
		self::assertSame( $seed['conversation_uuid'], $map_row['channel_case_ref'] );
	}

	/**
	 * 4. A topic lifecycle/unavailable event uses the real path and proves
	 * SC's idempotent unavailable-reporting operation plus provenance
	 * persistence: two real replay attempts of the same buffered row (the
	 * same class of retry item 2 exercises, here against the lifecycle
	 * branch) leave the real SC channel status degraded exactly once and
	 * write exactly one real handoff-map row.
	 */
	public function test_topic_lifecycle_event_reports_real_sc_channel_unavailable_idempotently_with_provenance(): void {
		$seed      = $this->seed_active_binding_and_conversation();
		$update_id = self::next_update_id();
		$decoded   = array(
			'message' => array(
				'forum_topic_closed' => array(),
			),
		);
		$record    = $this->buffer_and_fetch( $update_id, 'message', $decoded );

		$bot     = $this->ut_bots->find( $this->bot_id );
		$binding = $this->ut_bindings->find_by_bot_topic( $this->bot_id, $seed['topic_id'] );
		self::assertNotNull( $bot );
		self::assertNotNull( $binding );

		$outcome = $this->dispatcher->dispatch( $bot, $record, $binding, $decoded );
		self::assertSame( CutoverReplayDispatcher::OUTCOME_HANDED_OFF, $outcome );

		$conversation = $this->sc_conversations->find_by_uuid( $seed['conversation_uuid'] );
		self::assertNotNull( $conversation );
		$status = $this->sc_channel_status->status_for( $conversation->id() );
		self::assertSame( \UniversalSupportChat\ChannelContract\ChannelStatusRepository::STATUS_DEGRADED, $status['status'] );

		self::assertSame( 1, $this->map_row_count_for( $update_id ) );
		$map_row = $this->sc_handoff_map->find( $this->bot_id, $update_id );
		self::assertNotNull( $map_row );
		self::assertSame( 'channel_unavailable', $map_row['kind'] );

		// A second real replay of the identical row (matching provenance,
		// matching kind/channel_case_ref) must converge, not duplicate.
		$second = $this->ut_outbound_client->report_channel_unavailable(
			$binding->binding_uuid(),
			'telegram_topic_closed',
			$this->bot_id,
			$update_id
		);
		self::assertTrue( $second['ok'], (string) $second['reason'] );
		self::assertSame( 1, $this->map_row_count_for( $update_id ), 'A matching-provenance retry must never write a second real handoff-map row.' );
	}

	/**
	 * 5. A deliberately mismatched existing SC handoff-map row returns the
	 * real `409 handoff_provenance_conflict`; UT records its own UT-owned
	 * incident, performs no new SC domain write, and never marks
	 * `handed_off_at`.
	 */
	public function test_mismatched_existing_handoff_map_row_yields_real_409_conflict_and_ut_incident(): void {
		$seed_a = $this->seed_active_binding_and_conversation();
		$seed_b = $this->seed_active_binding_and_conversation();

		$update_id = self::next_update_id();

		// Seeds a real, committed handoff-map row for (bot_id, update_id)
		// pointing at a DIFFERENT conversation/kind than the row this test
		// will actually dispatch — a genuine provenance mismatch, not a
		// contrived duplicate of the same call.
		$this->sc_handoff_map->insert( $this->bot_id, $update_id, 'claim', $seed_b['conversation_uuid'], null );
		global $wpdb;
		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- durable test seeding, mirrors this suite's own established real-transaction precedent.

		$sender_id = 626262;
		$this->ut_operator_identities->create( 7, $sender_id, null, 1 );

		$decoded = array(
			'message' => array(
				'text' => 'This reply must be refused as a provenance conflict.',
				'from' => array( 'id' => $sender_id ),
			),
		);
		$record  = $this->buffer_and_fetch( $update_id, 'message', $decoded );

		$bot     = $this->ut_bots->find( $this->bot_id );
		$binding = $this->ut_bindings->find_by_bot_topic( $this->bot_id, $seed_a['topic_id'] );
		self::assertNotNull( $bot );
		self::assertNotNull( $binding );

		$outcome = $this->dispatcher->dispatch( $bot, $record, $binding, $decoded );

		self::assertSame( CutoverReplayDispatcher::OUTCOME_INCIDENT, $outcome );

		$row = $this->ut_deferred->find_by_id( $record->id() );
		self::assertNotNull( $row );
		self::assertSame( CutoverIncidentReason::HANDOFF_PROVENANCE_CONFLICT, $row['incident_reason'] );
		self::assertNull( $row['handed_off_at'] );

		// No new SC domain write: seed_a's real conversation must still
		// have zero messages.
		$conversation_a = $this->sc_conversations->find_by_uuid( $seed_a['conversation_uuid'] );
		self::assertNotNull( $conversation_a );
		self::assertCount( 0, $this->sc_messages->list_for_conversation( $conversation_a->id() ) );

		// The seeded mismatched row itself must remain the only row for
		// this (bot_id, update_id) — the conflict never overwrites it and
		// never adds a second one.
		self::assertSame( 1, $this->map_row_count_for( $update_id ) );
		$map_row = $this->sc_handoff_map->find( $this->bot_id, $update_id );
		self::assertNotNull( $map_row );
		self::assertSame( 'claim', $map_row['kind'] );
		self::assertSame( $seed_b['conversation_uuid'], $map_row['channel_case_ref'] );
	}

	/**
	 * 6. A UT-only pre-dispatch incident (an unsupported command) proves no
	 * Contract request is made at all and no real SC handoff-map row is
	 * ever created for it.
	 */
	public function test_ut_only_pre_dispatch_incident_makes_no_contract_request_and_writes_no_map_row(): void {
		$seed      = $this->seed_active_binding_and_conversation();
		$sender_id = 737373;
		$this->ut_operator_identities->create( 9, $sender_id, null, 1 );

		$update_id = self::next_update_id();
		$decoded   = array(
			'message' => array(
				'text'     => '/presence available',
				'entities' => array(
					array(
						'type'   => 'bot_command',
						'offset' => 0,
						'length' => 9,
					),
				),
				'from'     => array( 'id' => $sender_id ),
			),
		);
		$record    = $this->buffer_and_fetch( $update_id, 'message', $decoded );

		$bot     = $this->ut_bots->find( $this->bot_id );
		$binding = $this->ut_bindings->find_by_bot_topic( $this->bot_id, $seed['topic_id'] );
		self::assertNotNull( $bot );
		self::assertNotNull( $binding );

		$rest_calls_to_sc = 0;
		$observer         = static function ( $result, $server, $request ) use ( &$rest_calls_to_sc ) {
			if ( false !== strpos( $request->get_route(), '/universal-support-chat/v1/contract/' ) ) {
				++$rest_calls_to_sc;
			}

			return $result;
		};
		add_filter( 'rest_pre_dispatch', $observer, 10, 3 );

		$outcome = $this->dispatcher->dispatch( $bot, $record, $binding, $decoded );

		remove_filter( 'rest_pre_dispatch', $observer, 10 );

		self::assertSame( CutoverReplayDispatcher::OUTCOME_INCIDENT, $outcome );
		self::assertSame( 0, $rest_calls_to_sc, 'An unsupported command must never reach a real Support Chat Contract request.' );

		$row = $this->ut_deferred->find_by_id( $record->id() );
		self::assertNotNull( $row );
		self::assertSame( CutoverIncidentReason::UNSUPPORTED_COMMAND, $row['incident_reason'] );
		self::assertNull( $row['handed_off_at'] );

		self::assertSame( 0, $this->map_row_count_for( $update_id ), 'A UT-only pre-dispatch incident must never create a real SC handoff-map row.' );
	}

	/**
	 * 7. The actual wire request genuinely carries the source provenance
	 * fields, and Support Chat's own handoff-map row persists no
	 * plaintext, ciphertext, or content-derived digest — only ids, a
	 * fixed `kind` vocabulary, an opaque UUID reference, and timestamps.
	 */
	public function test_wire_request_carries_provenance_and_handoff_map_row_persists_no_content(): void {
		$seed      = $this->seed_active_binding_and_conversation();
		$sender_id = 848484;
		$this->ut_operator_identities->create( 11, $sender_id, null, 1 );

		$update_id  = self::next_update_id();
		$reply_text = 'Content that must never appear in the handoff-map row.';
		$decoded    = array(
			'message' => array(
				'text' => $reply_text,
				'from' => array( 'id' => $sender_id ),
			),
		);
		$record     = $this->buffer_and_fetch( $update_id, 'message', $decoded );

		$bot     = $this->ut_bots->find( $this->bot_id );
		$binding = $this->ut_bindings->find_by_bot_topic( $this->bot_id, $seed['topic_id'] );
		self::assertNotNull( $bot );
		self::assertNotNull( $binding );

		$captured_body = null;
		$observer      = static function ( $result, $server, $request ) use ( &$captured_body ) {
			if ( false !== strpos( $request->get_route(), '/universal-support-chat/v1/contract/ingest_operator_reply' ) ) {
				$captured_body = json_decode( $request->get_body(), true );
			}

			return $result;
		};
		add_filter( 'rest_pre_dispatch', $observer, 10, 3 );

		$outcome = $this->dispatcher->dispatch( $bot, $record, $binding, $decoded );

		remove_filter( 'rest_pre_dispatch', $observer, 10 );

		self::assertSame( CutoverReplayDispatcher::OUTCOME_HANDED_OFF, $outcome );
		self::assertIsArray( $captured_body, 'Expected to capture the real wire request to Support Chat.' );
		self::assertSame( $this->bot_id, $captured_body['source_bot_id'] ?? null );
		self::assertSame( $update_id, $captured_body['source_update_id'] ?? null );

		global $wpdb;
		$table = $wpdb->prefix . \UniversalSupportChat\Persistence\Migrator::LEGACY_HANDOFF_MAP_TABLE;
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE bot_id = %d AND update_id = %d", $this->bot_id, $update_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		self::assertIsArray( $row );
		self::assertSame(
			array( 'id', 'bot_id', 'update_id', 'kind', 'channel_case_ref', 'target_message_uuid', 'created_at' ),
			array_keys( $row ),
			'The real handoff-map row must carry only ids/uuid/kind/timestamp columns — no content column exists at all.'
		);
		foreach ( $row as $column => $value ) {
			if ( null === $value ) {
				continue;
			}
			self::assertStringNotContainsString( $reply_text, (string) $value, "Column {$column} of the real handoff-map row must never contain reply content." );
		}
	}
}
