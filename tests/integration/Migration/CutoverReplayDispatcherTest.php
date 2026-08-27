<?php
/**
 * Integration tests for the cohort-aware deferred-update dispatcher.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Tests\Integration\Migration;

use UniversalTelegram\Audit\AuditLogger;
use UniversalTelegram\Conversations\OperatorIdentityRepository;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Migration\CutoverIncidentReason;
use UniversalTelegram\Migration\CutoverReplayDispatcher;
use UniversalTelegram\Migration\DeferredUpdateRecord;
use UniversalTelegram\Migration\DeferredUpdateRepository;
use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Privacy\Redactor;
use UniversalTelegram\SupportChatAdapter\ChannelBinding;
use UniversalTelegram\SupportChatAdapter\ChannelBindingRepository;
use UniversalTelegram\SupportChatAdapter\Inbound\SupportChatContractClient;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;
use WP_UnitTestCase;

/**
 * ADR-0042 §3–§5: the four-way disposition taxonomy, against real
 * `ChannelBinding`/`OperatorIdentityRepository`/`DeferredUpdateRepository`
 * state. `SupportChatContractClient` is constructed with no collaborators
 * (its own established fail-closed-by-default test posture, identical to
 * `InboundAdapterBridgeNonInterferenceTest`'s own usage) — every call it
 * makes here fails closed, which is enough to prove the pre-dispatch
 * incident classification (parse/unmapped-sender/unsupported-command,
 * which never reach the client at all) and the transient-vs-incident
 * split for calls that do reach it. Genuine end-to-end success (a real
 * `{ok: true}` and a real handoff-map row) is proven only by the real
 * dual-plugin interop suite, not here — this repository alone cannot
 * fabricate a successful Support Chat response.
 */
final class CutoverReplayDispatcherTest extends WP_UnitTestCase {

	private SchemaHealth $schema_health;
	private ChannelBindingRepository $bindings;
	private OperatorIdentityRepository $operator_identities;
	private DeferredUpdateRepository $deferred;
	private CutoverReplayDispatcher $dispatcher;
	private BotProfileRepository $bots;

	private static int $next_unique_id = 920000000;

	protected function setUp(): void {
		parent::setUp();

		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . Migrator::SUPPORT_CHAT_BINDINGS_TABLE ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . Migrator::OPERATOR_IDENTITIES_TABLE ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . Migrator::QUIESCENCE_DEFERRED_UPDATES_TABLE ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$this->schema_health       = new SchemaHealth();
		$this->bindings            = new ChannelBindingRepository( $this->schema_health );
		$this->operator_identities = new OperatorIdentityRepository( $this->schema_health );
		$this->deferred            = new DeferredUpdateRepository( $this->schema_health, new CredentialVault() );
		$this->bots                = new BotProfileRepository( $this->schema_health, new CredentialVault() );
		$this->dispatcher          = new CutoverReplayDispatcher(
			$this->operator_identities,
			new SupportChatContractClient(),
			$this->deferred,
			new AuditLogger( $this->schema_health, new Redactor() )
		);
	}

	protected function tearDown(): void {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . Migrator::SUPPORT_CHAT_BINDINGS_TABLE ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . Migrator::OPERATOR_IDENTITIES_TABLE ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . Migrator::QUIESCENCE_DEFERRED_UPDATES_TABLE ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		parent::tearDown();
	}

	private static function unique_id(): int {
		return self::$next_unique_id++;
	}

	/**
	 * Seeds a real deferred-update row (unencrypted-in-test-friendly: we
	 * only need its id/bot_id/update_id, never its ciphertext, since this
	 * class never reads `payload_ciphertext`) and a real active binding for
	 * its `(bot_id, telegram_topic_id)`.
	 *
	 * @return array{record: DeferredUpdateRecord, binding: ChannelBinding, bot_id: int, update_id: int}
	 */
	private function seed_row_and_active_binding(): array {
		$bot_id            = self::unique_id();
		$update_id         = self::unique_id();
		$destination_id    = self::unique_id();
		$telegram_topic_id = self::unique_id();

		$this->deferred->buffer( $bot_id, $update_id, 'message', array( 'update_id' => $update_id ) );

		global $wpdb;
		$table = $wpdb->prefix . Migrator::QUIESCENCE_DEFERRED_UPDATES_TABLE;
		$id    = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE bot_id = %d AND update_id = %d", $bot_id, $update_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		$record = new DeferredUpdateRecord( $id, $bot_id, $update_id, 'message', current_time( 'mysql', true ), null );

		$binding = $this->bindings->create(
			wp_generate_uuid4(),
			wp_generate_uuid4(),
			'ensure-dispatch-' . $bot_id,
			$bot_id,
			$destination_id,
			$telegram_topic_id,
			ChannelBinding::STATUS_PREPARED
		);
		$this->assertNotNull( $binding );
		$this->assertTrue( $this->bindings->activate_prepared( $binding->binding_uuid(), $binding->cas_version() ) );
		$binding = $this->bindings->find_by_uuid( $binding->binding_uuid() );
		$this->assertNotNull( $binding );

		return array(
			'record'    => $record,
			'binding'   => $binding,
			'bot_id'    => $bot_id,
			'update_id' => $update_id,
		);
	}

	public function test_unsupported_command_is_a_durable_incident_never_a_silent_fallthrough(): void {
		$seed = $this->seed_row_and_active_binding();
		$bot  = $this->bots->create( 'Dispatcher Bot A', str_repeat( 'd', 46 ) );
		$this->assertNotNull( $bot );

		$decoded = array(
			'message' => array(
				'text'     => '/presence available',
				'entities' => array(
					array(
						'type'   => 'bot_command',
						'offset' => 0,
						'length' => 9, // The length of the word "/presence".
					),
				),
				'from'     => array( 'id' => 12345 ),
			),
		);

		$outcome = $this->dispatcher->dispatch( $bot, $seed['record'], $seed['binding'], $decoded );

		$this->assertSame( CutoverReplayDispatcher::OUTCOME_INCIDENT, $outcome );

		$row = $this->deferred->find_by_id( $seed['record']->id() );
		$this->assertNotNull( $row );
		$this->assertSame( CutoverIncidentReason::UNSUPPORTED_COMMAND, $row['incident_reason'] );
		$this->assertNull( $row['replayed_at'] );
		$this->assertNull( $row['handed_off_at'] );
	}

	public function test_unmapped_sender_is_a_durable_incident(): void {
		$seed = $this->seed_row_and_active_binding();
		$bot  = $this->bots->create( 'Dispatcher Bot B', str_repeat( 'e', 46 ) );
		$this->assertNotNull( $bot );

		$decoded = array(
			'message' => array(
				'text' => 'a genuine reply',
				'from' => array( 'id' => 999999 ), // No matching identity is ever created for this sender.
			),
		);

		$outcome = $this->dispatcher->dispatch( $bot, $seed['record'], $seed['binding'], $decoded );

		$this->assertSame( CutoverReplayDispatcher::OUTCOME_INCIDENT, $outcome );

		$row = $this->deferred->find_by_id( $seed['record']->id() );
		$this->assertNotNull( $row );
		$this->assertSame( CutoverIncidentReason::UNMAPPED_SENDER, $row['incident_reason'] );
	}

	public function test_message_with_no_text_is_a_parse_failed_incident(): void {
		$seed = $this->seed_row_and_active_binding();
		$bot  = $this->bots->create( 'Dispatcher Bot C', str_repeat( 'f', 46 ) );
		$this->assertNotNull( $bot );

		$this->operator_identities->create( self::unique_id(), 12345, null, 1 );

		$decoded = array(
			'message' => array(
				'from' => array( 'id' => 12345 ),
				// No 'text', no 'caption'.
			),
		);

		$outcome = $this->dispatcher->dispatch( $bot, $seed['record'], $seed['binding'], $decoded );

		$this->assertSame( CutoverReplayDispatcher::OUTCOME_INCIDENT, $outcome );

		$row = $this->deferred->find_by_id( $seed['record']->id() );
		$this->assertNotNull( $row );
		$this->assertSame( CutoverIncidentReason::PARSE_FAILED, $row['incident_reason'] );
	}

	public function test_well_formed_message_with_mapped_sender_but_unavailable_contract_client_is_retryable_not_an_incident(): void {
		$seed = $this->seed_row_and_active_binding();
		$bot  = $this->bots->create( 'Dispatcher Bot D', str_repeat( 'g', 46 ) );
		$this->assertNotNull( $bot );

		$this->operator_identities->create( self::unique_id(), 54321, null, 1 );

		$decoded = array(
			'message' => array(
				'text' => 'a genuine reply, correctly classified',
				'from' => array( 'id' => 54321 ),
			),
		);

		$outcome = $this->dispatcher->dispatch( $bot, $seed['record'], $seed['binding'], $decoded );

		// SupportChatContractClient() with no collaborators always fails
		// closed (adapter not paired) — this is a transport/availability
		// failure, never a provenance conflict, so it must be retryable,
		// never an incident.
		$this->assertSame( CutoverReplayDispatcher::OUTCOME_RETRY_TRANSIENT, $outcome );

		$row = $this->deferred->find_by_id( $seed['record']->id() );
		$this->assertNotNull( $row );
		$this->assertNull( $row['incident_reason'], 'A transient failure must never create an incident.' );
		$this->assertNull( $row['replayed_at'] );
		$this->assertNull( $row['handed_off_at'] );
	}

	public function test_topic_lifecycle_service_message_dispatches_via_report_channel_unavailable_path(): void {
		$seed = $this->seed_row_and_active_binding();
		$bot  = $this->bots->create( 'Dispatcher Bot E', str_repeat( 'h', 46 ) );
		$this->assertNotNull( $bot );

		$decoded = array(
			'message' => array(
				'forum_topic_closed' => array(),
			),
		);

		$outcome = $this->dispatcher->dispatch( $bot, $seed['record'], $seed['binding'], $decoded );

		// Same fail-closed client, so this is retryable — but critically
		// NOT an incident and NOT dispatched as a message/command: proving
		// the lifecycle-event branch was taken at all (a message/command
		// misclassification here would produce a different, wrong
		// incident_reason or a different outcome entirely).
		$this->assertSame( CutoverReplayDispatcher::OUTCOME_RETRY_TRANSIENT, $outcome );

		$row = $this->deferred->find_by_id( $seed['record']->id() );
		$this->assertNotNull( $row );
		$this->assertNull( $row['incident_reason'] );
	}
}
