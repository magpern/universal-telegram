<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Telegram\Commands;

use UniversalTelegram\Audit\AuditLogger;
use UniversalTelegram\Conversations\ChatProfileResolver;
use UniversalTelegram\Conversations\ConversationRepository;
use UniversalTelegram\Conversations\OperatorAvailabilityRepository;
use UniversalTelegram\Conversations\OperatorIdentityRepository;
use UniversalTelegram\Conversations\VisitorTokenGenerator;
use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;
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
use UniversalTelegram\Telegram\Commands\CommandParser;
use UniversalTelegram\Telegram\Commands\ConfirmationStore;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;
use UniversalTelegram\Telegram\Configuration\DestinationKind;
use UniversalTelegram\Telegram\Configuration\DestinationRepository;
use UniversalTelegram\Telegram\Outbound\MessageDispatcher;
use UniversalTelegram\Telegram\Outbound\OutboundMessageRepository;
use WP_UnitTestCase;

/**
 * ADR-0040 §2 entry point #5 / §3: BotCommandDispatcher::handle() refuses
 * outside idle on its own (defense-in-depth), except for a valid
 * DeferredReplayContext bound to the current epoch — the forge/bypass
 * proof required by §7.
 */
final class BotCommandDispatcherQuiescenceTest extends WP_UnitTestCase {

	private SchemaHealth $schema_health;
	private BotProfileRepository $bots;
	private ConversationRepository $conversations;
	private DestinationRepository $destinations;
	private OperatorIdentityRepository $operator_identities;
	private OutboundMessageRepository $outbound_messages;
	private BotCommandDispatcher $dispatcher;
	private QuiescenceGate $gate;

	protected function setUp(): void {
		parent::setUp();

		global $wpdb;
		$state_table = $wpdb->prefix . Migrator::QUIESCENCE_STATE_TABLE;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "UPDATE {$state_table} SET state = 'idle', updated_at = NOW() WHERE id = 1" );

		// QuiescenceGate's own transitions and decide_webhook_disposition()/
		// attempt_replaying_to_idle() open explicit transactions
		// (START TRANSACTION/COMMIT), which — on the same connection —
		// implicitly commits WP_UnitTestCase's own outer per-test
		// transaction the instant this test calls into them, so rows
		// written earlier in the same test method are never rolled back
		// afterward. Explicit cleanup, not reliance on rollback, for every
		// table this test writes to.
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'universal_telegram_operator_identities' );
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'universal_telegram_destinations' );
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'universal_telegram_bots' );
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'universal_telegram_outbound_messages' );
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . Migrator::QUIESCENCE_DEFERRED_UPDATES_TABLE ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$this->schema_health = new SchemaHealth();
		$vault               = new CredentialVault();
		$audit               = new AuditLogger( $this->schema_health, new Redactor() );

		$this->bots                = new BotProfileRepository( $this->schema_health, $vault );
		$this->conversations       = new ConversationRepository( $this->schema_health, new CredentialVault(), new VisitorTokenGenerator() );
		$this->destinations        = new DestinationRepository( $this->schema_health );
		$this->operator_identities = new OperatorIdentityRepository( $this->schema_health );

		$this->outbound_messages = new OutboundMessageRepository( $this->schema_health, $vault );
		$message_dispatcher      = new MessageDispatcher( $this->outbound_messages, new Dispatcher( $this->schema_health ) );

		$this->gate = new QuiescenceGate(
			$this->schema_health,
			new DeferredUpdateRepository( $this->schema_health, $vault ),
			new QuiescenceTransitionRepository()
		);

		$this->dispatcher = new BotCommandDispatcher(
			$this->operator_identities,
			$this->conversations,
			new ChatProfileResolver( $this->bots, $this->destinations ),
			new OperatorAvailabilityRepository( $this->schema_health ),
			new QueueHealth(),
			new EventHistoryRepository( $this->schema_health, new Registry(), new Redactor() ),
			new WooCommerceSupport(),
			new WooCommerceCommandQueryService(),
			new ConfirmationStore(),
			$message_dispatcher,
			$audit,
			$this->gate
		);
	}

	private function mapped_operator(): int {
		$operator = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		get_role( 'subscriber' )->add_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );

		return $operator;
	}

	private function last_outbound_text(): ?string {
		global $wpdb;
		$table = $wpdb->prefix . Migrator::OUTBOUND_MESSAGES_TABLE;
		$id    = $wpdb->get_var( "SELECT id FROM {$table} ORDER BY id DESC LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( null === $id ) {
			return null;
		}

		$message = $this->outbound_messages->find( (int) $id );

		return null === $message ? null : $this->outbound_messages->decrypt_body( $message )->plaintext();
	}

	private function outbound_count(): int {
		global $wpdb;
		$table = $wpdb->prefix . Migrator::OUTBOUND_MESSAGES_TABLE;

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	private function whoami( $bot, ?\UniversalTelegram\Migration\DeferredReplayContext $context = null ): void {
		$parsed = CommandParser::parse(
			array(
				'text'     => '/whoami',
				'entities' => array(
					array(
						'type'   => 'bot_command',
						'offset' => 0,
						'length' => 7,
					),
				),
			),
			$bot->telegram_username()
		);

		$this->dispatcher->handle(
			$bot,
			'-100123',
			null,
			$parsed,
			array( 'message' => array( 'from' => array( 'id' => 1 ) ) ),
			$context
		);
	}

	public function test_command_is_refused_when_not_idle_and_no_replay_context_is_supplied(): void {
		$bot = $this->bots->create( 'Support Bot', 'token' );
		$this->destinations->create( $bot->id(), DestinationKind::SUPERGROUP, '-100123', null, 'Support group' );
		$operator = $this->mapped_operator();
		$this->operator_identities->create( $operator, 1, null, 1 );

		$this->gate->enter();

		$this->whoami( $bot );

		$this->assertSame( 0, $this->outbound_count(), 'No reply may be sent while blocked.' );
	}

	public function test_command_proceeds_normally_while_idle(): void {
		$bot = $this->bots->create( 'Support Bot', 'token' );
		$this->destinations->create( $bot->id(), DestinationKind::SUPERGROUP, '-100123', null, 'Support group' );
		$operator = $this->mapped_operator();
		$this->operator_identities->create( $operator, 1, null, 1 );

		$this->whoami( $bot );

		$this->assertStringContainsString( 'You are mapped as', (string) $this->last_outbound_text() );
	}

	public function test_a_valid_replay_context_bound_to_the_current_epoch_permits_dispatch_while_replaying(): void {
		$bot = $this->bots->create( 'Support Bot', 'token' );
		$this->destinations->create( $bot->id(), DestinationKind::SUPERGROUP, '-100123', null, 'Support group' );
		$operator = $this->mapped_operator();
		$this->operator_identities->create( $operator, 1, null, 1 );

		$this->gate->enter();
		$this->gate->confirm();
		$this->gate->exit();

		$context = $this->gate->issue_replay_context();
		$this->assertNotNull( $context );

		$this->whoami( $bot, $context );

		$this->assertStringContainsString( 'You are mapped as', (string) $this->last_outbound_text(), 'A valid, epoch-matched replay context must permit dispatch during replaying.' );
	}

	public function test_a_stale_replay_context_from_a_prior_epoch_is_rejected(): void {
		$bot = $this->bots->create( 'Support Bot', 'token' );
		$this->destinations->create( $bot->id(), DestinationKind::SUPERGROUP, '-100123', null, 'Support group' );
		$operator = $this->mapped_operator();
		$this->operator_identities->create( $operator, 1, null, 1 );

		$this->gate->enter();
		$this->gate->confirm();
		$this->gate->exit();
		$stale_context = $this->gate->issue_replay_context();
		$this->gate->attempt_replaying_to_idle();

		// A fresh epoch.
		$this->gate->enter();

		$this->assertNotNull( $stale_context );
		$this->whoami( $bot, $stale_context );

		$this->assertSame( 0, $this->outbound_count(), 'A context minted for a prior epoch must never grant passage in a later one.' );
	}
}
