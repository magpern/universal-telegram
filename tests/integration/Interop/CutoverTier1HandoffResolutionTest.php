<?php
/**
 * SC-M03 final-cutover Tier 1 characterization: does the cohort-aware
 * deferred-update handoff resolve a REAL prepared binding (one produced by
 * the real WP5 `LegacyBindingImportServiceV1`, which mints an independent
 * opaque `binding_uuid`) to its Support Chat conversation?
 *
 * `CutoverHandoffIntegrationTest` only ever passes because it deliberately
 * seeds `binding_uuid == conversation_uuid` ("test-fixture-only", per its
 * own docblock and the final-cutover closure addendum). This test asks the
 * question the disposable DEV rehearsal must answer: with a binding whose
 * `binding_uuid` is a real, independent UUID (every real binding-creation
 * path — `EnsureChannelCaseService` and `LegacyBindingImportServiceV1` —
 * mints one), does the real `CutoverReplayDispatcher` -> real
 * `SupportChatContractClient` -> real Support Chat `ContractOperationDispatcher`
 * chain hand the update off?
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Tests\Integration\Interop;

use UniversalSupportChat\Persistence\Migrator as ScMigrator;
use UniversalSupportChat\Persistence\SchemaHealth as ScSchemaHealth;
use UniversalSupportChat\ChannelContract\HandoffMapRepository as ScHandoffMapRepository;
use UniversalTelegram\Audit\AuditLogger as UtAuditLogger;
use UniversalTelegram\Conversations\OperatorIdentityRepository;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Migration\CutoverReplayDispatcher;
use UniversalTelegram\Migration\DeferredUpdateRecord;
use UniversalTelegram\Migration\DeferredUpdateRepository;
use UniversalTelegram\Persistence\Migrator as UtMigrator;
use UniversalTelegram\Persistence\SchemaHealth as UtSchemaHealth;
use UniversalTelegram\Privacy\Redactor as UtRedactor;
use UniversalTelegram\SupportChatAdapter\ChannelBinding;

if ( ! defined( 'WP_CLI' ) ) {
	// Mirrors QuiescenceProviderIntegrationTest's identical file-level
	// guard: the WP5/cutover boundaries self-check `defined('WP_CLI') && WP_CLI`
	// exactly as the real migration WP-CLI process would.
	define( 'WP_CLI', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
}

/**
 * @coversNothing Characterization / rehearsal-gap probe, not a unit under test.
 */
final class CutoverTier1HandoffResolutionTest extends InteropTestCase {

	private DeferredUpdateRepository $ut_deferred;
	private OperatorIdentityRepository $ut_operator_identities;
	private CutoverReplayDispatcher $dispatcher;
	private ScHandoffMapRepository $sc_handoff_map;

	private static int $next_topic_id  = 940000;
	private static int $next_update_id = 950000;

	protected function setUp(): void {
		$this->clean();
		parent::setUp();

		$ut_schema                    = new UtSchemaHealth();
		$this->ut_deferred            = new DeferredUpdateRepository( $ut_schema, new CredentialVault() );
		$this->ut_operator_identities = new OperatorIdentityRepository( $ut_schema );
		$this->dispatcher             = new CutoverReplayDispatcher(
			$this->ut_operator_identities,
			$this->ut_outbound_client,
			$this->ut_deferred,
			new UtAuditLogger( $ut_schema, new UtRedactor() )
		);
		$this->sc_handoff_map         = new ScHandoffMapRepository( new ScSchemaHealth() );
	}

	protected function tearDown(): void {
		$this->clean();
		parent::tearDown();
	}

	private function clean(): void {
		global $wpdb;
		$tables = array(
			$wpdb->prefix . UtMigrator::QUIESCENCE_DEFERRED_UPDATES_TABLE,
			$wpdb->prefix . UtMigrator::SUPPORT_CHAT_BINDINGS_TABLE,
			$wpdb->prefix . UtMigrator::OPERATOR_IDENTITIES_TABLE,
			$wpdb->prefix . UtMigrator::CONVERSATION_MESSAGES_TABLE,
			$wpdb->prefix . UtMigrator::CONVERSATIONS_TABLE,
			$wpdb->prefix . UtMigrator::BOTS_TABLE,
			$wpdb->prefix . ScMigrator::LEGACY_HANDOFF_MAP_TABLE,
			$wpdb->prefix . ScMigrator::CHANNEL_STATUS_TABLE,
			$wpdb->prefix . ScMigrator::CONVERSATION_MESSAGES_TABLE,
			$wpdb->prefix . ScMigrator::CONVERSATIONS_TABLE,
		);
		foreach ( $tables as $table ) {
			$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- fixed table name from a Migrator constant, test cleanup only.
		}
		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- durable test cleanup, mirrors CutoverHandoffIntegrationTest's established precedent.
	}

	private function buffer_and_fetch( int $update_id, array $payload ): DeferredUpdateRecord {
		self::assertTrue( $this->ut_deferred->buffer( $this->bot_id, $update_id, 'message', $payload ) );
		global $wpdb;
		$table = $wpdb->prefix . UtMigrator::QUIESCENCE_DEFERRED_UPDATES_TABLE;
		$id    = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE bot_id = %d AND update_id = %d", $this->bot_id, $update_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		return new DeferredUpdateRecord( $id, $this->bot_id, $update_id, 'message', current_time( 'mysql', true ), null );
	}

	/**
	 * Positive control — the fixture convention CutoverHandoffIntegrationTest
	 * relies on: when binding_uuid == the SC conversation UUID, the real
	 * chain hands off.
	 */
	public function test_handoff_succeeds_when_binding_uuid_equals_conversation_uuid(): void {
		$conversation_uuid = $this->create_sc_conversation();
		$topic_id          = self::$next_topic_id++;
		$binding           = $this->ut_bindings->create( $conversation_uuid, $conversation_uuid, 'probe-equal-' . $topic_id, $this->bot_id, $this->parent_destination->id(), $topic_id, ChannelBinding::STATUS_ACTIVE );
		self::assertNotNull( $binding );

		$sender_id = 8100001;
		$this->ut_operator_identities->create( 1, $sender_id, null, 1 );

		$update_id = self::$next_update_id++;
		$decoded   = array(
			'message' => array(
				'text' => 'control reply',
				'from' => array( 'id' => $sender_id ),
			),
		);
		$record    = $this->buffer_and_fetch( $update_id, $decoded );
		$bot       = $this->ut_bots->find( $this->bot_id );

		$outcome = $this->dispatcher->dispatch( $bot, $record, $binding, $decoded );

		self::assertSame( CutoverReplayDispatcher::OUTCOME_HANDED_OFF, $outcome, 'Control: binding_uuid == conversation_uuid must hand off.' );
		self::assertNotNull( $this->sc_handoff_map->find( $this->bot_id, $update_id ) );
	}

	/**
	 * FINDING F1 — a REAL prepared binding produced by the real WP5
	 * `LegacyBindingImportServiceV1` (independent opaque binding_uuid, the
	 * only kind a real cutover cohort ever activates) is NOT resolvable by
	 * Support Chat's `resolve_conversation()`, which treats channel_case_ref
	 * strictly as its own conversation_uuid. The handoff never completes:
	 * outcome is retry-transient (not handed off, not an incident), no SC
	 * message, no handoff-map row, no handed_off_at.
	 */
	public function test_handoff_does_not_resolve_a_real_legacy_bind_prepared_binding(): void {
		// Real SC conversation (Phase A would create this; here we create it
		// directly, then hand its UUID to legacy-bind as the WP5 candidate does).
		$conversation_uuid = $this->create_sc_conversation();

		// Real UT legacy conversation with a real created, active topic.
		$ut      = \UniversalTelegram\Core\Plugin::instance();
		$ut_conv = $ut->conversation_repository();
		$owner   = self::factory()->user->create();
		$legacy  = $ut_conv->create( wp_generate_uuid4(), 'hashed-secret', $this->bot_id, 'sales', null, $owner );
		self::assertNotNull( $legacy );
		$topic_id = self::$next_topic_id++;
		self::assertTrue( $ut_conv->mark_topic_created( $legacy->id(), $topic_id, $this->parent_destination->id() ) );
		self::assertTrue( $ut_conv->mark_topic_lifecycle( $legacy->id(), 'active' ) );

		// Enter a real quiescence window so the real lock-scoped assertion
		// inside LegacyBindingImportServiceV1::import_batch() is satisfied.
		$ut_schema = new UtSchemaHealth();
		$gate      = new \UniversalTelegram\Migration\QuiescenceGate( $ut_schema, $this->ut_deferred, new \UniversalTelegram\Migration\QuiescenceTransitionRepository() );
		self::assertTrue( $gate->enter() );
		self::assertTrue( $gate->confirm()['success'] );

		// Real WP5 binding preparation — the real boundary, mints its own binding_uuid.
		$service = $ut->legacy_binding_import_service();
		self::assertNotNull( $service );
		$results = $service->import_batch(
			array(
				array(
					'source_conversation_id'    => $legacy->id(),
					'bot_id'                    => $this->bot_id,
					'destination_id'            => $this->parent_destination->id(),
					'telegram_topic_id'         => $topic_id,
					'support_conversation_uuid' => $conversation_uuid,
				),
			),
			false
		);
		self::assertSame( 'created', $results[0]['outcome'], 'legacy-bind should create a prepared binding.' );

		$binding = $this->ut_bindings->find_by_bot_topic( $this->bot_id, $topic_id );
		self::assertNotNull( $binding );
		self::assertSame( ChannelBinding::STATUS_PREPARED, $binding->status() );
		self::assertNotSame(
			$conversation_uuid,
			$binding->binding_uuid(),
			'Real legacy-bind mints an independent binding_uuid — this is the whole point of F1.'
		);

		// Activate it (cutover activate does exactly this CAS write).
		self::assertTrue( $this->ut_bindings->activate_prepared( $binding->binding_uuid(), $binding->cas_version() ) );
		$active = $this->ut_bindings->find_by_uuid( $binding->binding_uuid() );
		self::assertTrue( $active->is_active() );

		// A real buffered operator reply for the activated cohort topic.
		$sender_id = 8100002;
		$this->ut_operator_identities->create( 1, $sender_id, null, 1 );
		$update_id = self::$next_update_id++;
		$decoded   = array(
			'message' => array(
				'text' => 'a real reply for a real legacy-bind cohort topic',
				'from' => array( 'id' => $sender_id ),
			),
		);
		$record    = $this->buffer_and_fetch( $update_id, $decoded );
		$bot       = $this->ut_bots->find( $this->bot_id );

		// The real cohort-aware handoff dispatch.
		$outcome = $this->dispatcher->dispatch( $bot, $record, $active, $decoded );

		self::assertSame(
			CutoverReplayDispatcher::OUTCOME_RETRY_TRANSIENT,
			$outcome,
			'F1: SC cannot resolve the independent binding_uuid to a conversation, so the handoff returns 404 not_found -> retry-transient, never handed off, never an incident.'
		);

		$row = $this->ut_deferred->find_by_id( $record->id() );
		self::assertNull( $row['handed_off_at'], 'F1: handed_off_at must not be stamped.' );
		self::assertNull( $row['incident_reason'], 'F1: not an incident either — it is silently retryable forever, so replaying->idle and confirm-complete stay blocked.' );

		self::assertNull( $this->sc_handoff_map->find( $this->bot_id, $update_id ), 'F1: no SC handoff-map row.' );

		$sc_conversation = $this->sc_conversations->find_by_uuid( $conversation_uuid );
		self::assertNotNull( $sc_conversation );
		self::assertCount( 0, $this->sc_messages->list_for_conversation( $sc_conversation->id() ), 'F1: no SC message created.' );

		// Reset the real quiescence state this test opened, back to idle.
		global $wpdb;
		$state_table       = $wpdb->prefix . UtMigrator::QUIESCENCE_STATE_TABLE;
		$transitions_table = $wpdb->prefix . UtMigrator::QUIESCENCE_TRANSITIONS_TABLE;
		$wpdb->query( "UPDATE {$state_table} SET state = 'idle', entered_draining_at = NULL, entered_quiescent_at = NULL, entered_replaying_at = NULL, updated_at = NOW() WHERE id = 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- fixed table name, test cleanup only.
		$wpdb->query( "DELETE FROM {$transitions_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- fixed table name, test cleanup only.
		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- durable test cleanup.
	}
}
