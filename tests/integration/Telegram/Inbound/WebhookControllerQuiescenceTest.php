<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Telegram\Inbound;

use UniversalTelegram\Audit\AuditLogger;
use UniversalTelegram\Conversations\ChatProfileResolver;
use UniversalTelegram\Conversations\ConversationRepository;
use UniversalTelegram\Conversations\MessageRepository;
use UniversalTelegram\Conversations\OperatorAvailabilityRepository;
use UniversalTelegram\Conversations\OperatorIdentityRepository;
use UniversalTelegram\Conversations\VisitorTokenGenerator;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Events\EventHistoryRepository;
use UniversalTelegram\Events\Registry;
use UniversalTelegram\Integrations\WooCommerce\WooCommerceCommandQueryService;
use UniversalTelegram\Integrations\WooCommerce\WooCommerceSupport;
use UniversalTelegram\Migration\DeferredUpdateRepository;
use UniversalTelegram\Migration\QuiescenceGate;
use UniversalTelegram\Migration\QuiescenceTransitionRepository;
use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Privacy\Redactor;
use UniversalTelegram\Queue\Dispatcher;
use UniversalTelegram\Queue\QueueHealth;
use UniversalTelegram\Telegram\Commands\BotCommandDispatcher;
use UniversalTelegram\Telegram\Commands\ConfirmationStore;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;
use UniversalTelegram\Telegram\Configuration\DestinationRepository;
use UniversalTelegram\Telegram\Inbound\UpdateRepository;
use UniversalTelegram\Telegram\Inbound\WebhookController;
use UniversalTelegram\Telegram\Inbound\WebhookSecretVerifier;
use UniversalTelegram\Telegram\Outbound\MessageDispatcher;
use UniversalTelegram\Telegram\Outbound\OutboundMessageRepository;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * ADR-0040 §3/§7: encrypted buffer-and-replay for the Telegram webhook —
 * every state except idle buffers instead of processing, duplicate
 * deliveries are idempotent, and no plaintext is ever recoverable under a
 * different (bot_id, update_id) AAD context.
 */
final class WebhookControllerQuiescenceTest extends WP_UnitTestCase {

	private BotProfileRepository $bots;
	private WebhookController $controller;
	private QuiescenceGate $gate;
	private DeferredUpdateRepository $deferred;
	private CredentialVault $vault;

	protected function setUp(): void {
		parent::setUp();

		global $wpdb;
		$state_table = $wpdb->prefix . Migrator::QUIESCENCE_STATE_TABLE;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "UPDATE {$state_table} SET state = 'idle', updated_at = NOW() WHERE id = 1" );
		$deferred_table = $wpdb->prefix . Migrator::QUIESCENCE_DEFERRED_UPDATES_TABLE;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$deferred_table}" );

		$schema_health = new SchemaHealth();
		$this->vault   = new CredentialVault();
		$audit_logger  = new AuditLogger( $schema_health, new Redactor() );

		$this->bots     = new BotProfileRepository( $schema_health, $this->vault );
		$updates        = new UpdateRepository( $schema_health );
		$conversations  = new ConversationRepository( $schema_health, new CredentialVault(), new VisitorTokenGenerator() );
		$destinations   = new DestinationRepository( $schema_health );
		$messages       = new MessageRepository( $schema_health, $this->vault );
		$verifier       = new WebhookSecretVerifier( $this->bots, $audit_logger );
		$this->deferred = new DeferredUpdateRepository( $schema_health, $this->vault );
		$this->gate     = new QuiescenceGate( $schema_health, $this->deferred, new QuiescenceTransitionRepository() );

		$outbound_messages  = new OutboundMessageRepository( $schema_health, $this->vault );
		$message_dispatcher = new MessageDispatcher( $outbound_messages, new Dispatcher( $schema_health ) );
		$bot_commands       = new BotCommandDispatcher(
			new OperatorIdentityRepository( $schema_health ),
			$conversations,
			new ChatProfileResolver( $this->bots, $destinations ),
			new OperatorAvailabilityRepository( $schema_health ),
			new QueueHealth(),
			new EventHistoryRepository( $schema_health, new Registry(), new Redactor() ),
			new WooCommerceSupport(),
			new WooCommerceCommandQueryService(),
			new ConfirmationStore(),
			$message_dispatcher,
			$audit_logger,
			$this->gate
		);

		$this->controller = new WebhookController(
			$schema_health,
			$this->bots,
			$verifier,
			$updates,
			$conversations,
			$messages,
			new ChatProfileResolver( $this->bots, $destinations ),
			new OperatorIdentityRepository( $schema_health ),
			$audit_logger,
			$bot_commands,
			1048576,
			null,
			$this->gate
		);
	}

	/**
	 * QuiescenceGate's own transition CAS commits mid-test, which also
	 * commits any real MessageDispatcher-enqueued Action Scheduler action
	 * past WP_UnitTestCase's own rollback — cleaned explicitly so it never
	 * leaks into a later, unrelated test file.
	 */
	protected function tearDown(): void {
		global $wpdb;
		// A raw delete, not the Store API: the CAS commit above can leave
		// this connection's view of Action Scheduler's own store in a
		// state where query_actions()/delete_action() no longer reliably
		// see a row already committed moments earlier on this same
		// connection. A direct DELETE against its own table is unambiguous.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}actionscheduler_actions WHERE hook = %s", \UniversalTelegram\Queue\WorkerRunner::HOOK ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		parent::tearDown();
	}

	private function request_for( string $bot_uuid, string $secret, string $body ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/universal-telegram/v1/webhook/' . $bot_uuid );
		$request->set_url_params( array( 'bot_uuid' => $bot_uuid ) );
		$request->set_header( 'X-Telegram-Bot-Api-Secret-Token', $secret );
		$request->set_body( $body );

		return $request;
	}

	private function active_secret_for( \UniversalTelegram\Telegram\Configuration\BotProfile $bot ): string {
		return $this->bots->decrypt_webhook_secret( $bot )->plaintext();
	}

	public function test_update_is_buffered_not_processed_while_draining(): void {
		$this->gate->enter();

		$bot    = $this->bots->create( 'Bot', 'token' );
		$secret = $this->active_secret_for( $bot );
		$body   = wp_json_encode(
			array(
				'update_id' => 300,
				'message'   => array( 'chat' => array( 'id' => 555 ) ),
			)
		);

		$response = $this->controller->handle_request( $this->request_for( $bot->bot_uuid(), $secret, $body ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, $this->gate->deferred_update_backlog_count() );

		global $wpdb;
		$updates_table = $wpdb->prefix . 'universal_telegram_inbound_updates';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$updates_table} WHERE bot_id = {$bot->id()} AND update_id = 300" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$this->assertSame( 0, $count, 'A buffered update must never reach the live dedup/processing pipeline.' );
	}

	public function test_update_is_buffered_while_quiescent_and_while_replaying(): void {
		$bot    = $this->bots->create( 'Bot', 'token' );
		$secret = $this->active_secret_for( $bot );

		foreach ( array( 'quiescent', 'replaying' ) as $index => $state ) {
			global $wpdb;
			$state_table = $wpdb->prefix . Migrator::QUIESCENCE_STATE_TABLE;
			$wpdb->update( $state_table, array( 'state' => $state ), array( 'id' => 1 ) );

			$update_id = 400 + $index;
			$body      = wp_json_encode(
				array(
					'update_id' => $update_id,
					'message'   => array( 'chat' => array( 'id' => 555 ) ),
				)
			);
			$response  = $this->controller->handle_request( $this->request_for( $bot->bot_uuid(), $secret, $body ) );

			$this->assertSame( 200, $response->get_status(), "state={$state}" );
			$this->assertTrue( $this->deferred->exists( $bot->id(), $update_id ), "state={$state}" );
		}
	}

	public function test_duplicate_delivery_while_buffering_is_idempotent(): void {
		$this->gate->enter();

		$bot    = $this->bots->create( 'Bot', 'token' );
		$secret = $this->active_secret_for( $bot );
		$body   = wp_json_encode(
			array(
				'update_id' => 500,
				'message'   => array( 'chat' => array( 'id' => 555 ) ),
			)
		);

		$first  = $this->controller->handle_request( $this->request_for( $bot->bot_uuid(), $secret, $body ) );
		$second = $this->controller->handle_request( $this->request_for( $bot->bot_uuid(), $secret, $body ) );

		$this->assertSame( 200, $first->get_status() );
		$this->assertSame( 200, $second->get_status() );
		$this->assertSame( 1, $this->gate->deferred_update_backlog_count(), 'Exactly one row must exist after a duplicate delivery.' );
	}

	public function test_buffered_payload_is_never_recoverable_in_plaintext_via_the_response(): void {
		$this->gate->enter();

		$bot    = $this->bots->create( 'Bot', 'token' );
		$secret = $this->active_secret_for( $bot );
		$body   = wp_json_encode(
			array(
				'update_id' => 600,
				'message'   => array(
					'text' => 'top secret visitor message',
					'chat' => array( 'id' => 555 ),
				),
			)
		);

		$response = $this->controller->handle_request( $this->request_for( $bot->bot_uuid(), $secret, $body ) );

		$this->assertSame( array( 'ok' => true ), $response->get_data() );

		global $wpdb;
		$table = $wpdb->prefix . Migrator::QUIESCENCE_DEFERRED_UPDATES_TABLE;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ciphertext = $wpdb->get_var( "SELECT payload_ciphertext FROM {$table} WHERE bot_id = {$bot->id()} AND update_id = 600" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$this->assertStringNotContainsString( 'top secret visitor message', (string) $ciphertext );
	}

	public function test_ciphertext_fails_to_decrypt_under_a_different_update_ids_aad_context(): void {
		$this->gate->enter();

		$bot    = $this->bots->create( 'Bot', 'token' );
		$secret = $this->active_secret_for( $bot );
		$body   = wp_json_encode(
			array(
				'update_id' => 700,
				'message'   => array(
					'text' => 'body',
					'chat' => array( 'id' => 555 ),
				),
			)
		);

		$this->controller->handle_request( $this->request_for( $bot->bot_uuid(), $secret, $body ) );

		global $wpdb;
		$table = $wpdb->prefix . Migrator::QUIESCENCE_DEFERRED_UPDATES_TABLE;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ciphertext = $wpdb->get_var( "SELECT payload_ciphertext FROM {$table} WHERE bot_id = {$bot->id()} AND update_id = 700" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// The correct context decrypts.
		$correct = $this->vault->decrypt( $ciphertext, "quiescence-deferred-update:{$bot->id()}:700" );
		$this->assertSame( \UniversalTelegram\Core\Security\CredentialState::AVAILABLE, $correct->state() );

		// A different update_id's context does not.
		$wrong = $this->vault->decrypt( $ciphertext, "quiescence-deferred-update:{$bot->id()}:999" );
		$this->assertNotSame( \UniversalTelegram\Core\Security\CredentialState::AVAILABLE, $wrong->state() );
	}

	public function test_a_live_update_processes_normally_while_idle(): void {
		$bot    = $this->bots->create( 'Bot', 'token' );
		$secret = $this->active_secret_for( $bot );
		$body   = wp_json_encode(
			array(
				'update_id' => 800,
				'message'   => array( 'chat' => array( 'id' => 555 ) ),
			)
		);

		$response = $this->controller->handle_request( $this->request_for( $bot->bot_uuid(), $secret, $body ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 0, $this->gate->deferred_update_backlog_count() );

		global $wpdb;
		$updates_table = $wpdb->prefix . 'universal_telegram_inbound_updates';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$updates_table} WHERE bot_id = {$bot->id()} AND update_id = 800" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$this->assertSame( 1, $count );
	}

	public function test_process_update_replays_a_deferred_row_through_the_identical_pipeline(): void {
		$bot    = $this->bots->create( 'Bot', 'token' );
		$secret = $this->active_secret_for( $bot );

		$this->gate->enter();
		$body = wp_json_encode(
			array(
				'update_id' => 900,
				'message'   => array( 'chat' => array( 'id' => 555 ) ),
			)
		);
		$this->controller->handle_request( $this->request_for( $bot->bot_uuid(), $secret, $body ) );

		$this->gate->confirm();
		$this->gate->exit();

		$context = $this->gate->issue_replay_context();
		$this->assertNotNull( $context );

		$records = $this->deferred->unreplayed_grouped_by_bot();
		$record  = $records[ $bot->id() ][0];
		$payload = $this->deferred->decrypt_payload( $record );

		$this->assertNotNull( $payload );

		$this->controller->process_update( $bot, $payload, $context );
		$this->deferred->mark_replayed( $record->id() );

		global $wpdb;
		$updates_table = $wpdb->prefix . 'universal_telegram_inbound_updates';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$updates_table} WHERE bot_id = {$bot->id()} AND update_id = 900" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$this->assertSame( 1, $count );
		$this->assertSame( 0, $this->gate->deferred_update_backlog_count() );
	}
}
