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
use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Privacy\Redactor;
use UniversalTelegram\Queue\Dispatcher;
use UniversalTelegram\Queue\QueueHealth;
use UniversalTelegram\Telegram\Commands\BotCommandDispatcher;
use UniversalTelegram\Telegram\Commands\CommandParser;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;
use UniversalTelegram\Telegram\Configuration\DestinationKind;
use UniversalTelegram\Telegram\Configuration\DestinationRepository;
use UniversalTelegram\Telegram\Outbound\MessageDispatcher;
use UniversalTelegram\Telegram\Outbound\OutboundMessageRepository;
use WP_UnitTestCase;

/**
 * M08 WP4: /status, /errors, /visitors — bounded site/queue/visitor
 * aggregates reused from the existing Diagnostics data boundary.
 */
final class BotCommandDispatcherFamilyBCTest extends WP_UnitTestCase {

	private SchemaHealth $schema_health;
	private BotProfileRepository $bots;
	private DestinationRepository $destinations;
	private OperatorIdentityRepository $operator_identities;
	private OutboundMessageRepository $outbound_messages;
	private BotCommandDispatcher $dispatcher;

	protected function setUp(): void {
		parent::setUp();

		$this->schema_health = new SchemaHealth();
		$vault                = new CredentialVault();
		$audit                = new AuditLogger( $this->schema_health, new Redactor() );

		$this->bots                = new BotProfileRepository( $this->schema_health, $vault );
		$conversations              = new ConversationRepository( $this->schema_health, new CredentialVault(), new VisitorTokenGenerator() );
		$this->destinations        = new DestinationRepository( $this->schema_health );
		$this->operator_identities = new OperatorIdentityRepository( $this->schema_health );
		$availability               = new OperatorAvailabilityRepository( $this->schema_health );

		$this->outbound_messages = new OutboundMessageRepository( $this->schema_health, $vault );
		$message_dispatcher      = new MessageDispatcher( $this->outbound_messages, new Dispatcher( $this->schema_health ) );

		$this->dispatcher = new BotCommandDispatcher(
			$this->operator_identities,
			$conversations,
			new ChatProfileResolver( $this->bots, $this->destinations ),
			$availability,
			new QueueHealth(),
			new EventHistoryRepository( $this->schema_health, new Registry(), new Redactor() ),
			new \UniversalTelegram\Integrations\WooCommerce\WooCommerceSupport(),
			new \UniversalTelegram\Integrations\WooCommerce\WooCommerceCommandQueryService(),
			new \UniversalTelegram\Telegram\Commands\ConfirmationStore(),
			$message_dispatcher,
			$audit
		);
	}

	private function mapped_operator(): array {
		$operator = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$role     = get_role( 'subscriber' );
		$role->add_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );

		return array( $operator, $role );
	}

	private function insert_history_row( string $event_id, string $source, string $occurred_at ): void {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . Migrator::EVENT_HISTORY_TABLE,
			array(
				'event_id'              => $event_id,
				'event_type'            => 'custom.test_event',
				'schema_version'        => 1,
				'occurred_at'           => $occurred_at,
				'source'                => $source,
				'projected_fields_json' => '{}',
				'created_at'            => current_time( 'mysql', true ),
			)
		);
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

	private function handle( $bot, string $command_text, int $entity_length, int $sender_telegram_user_id ): void {
		$parsed = CommandParser::parse(
			array(
				'text'     => $command_text,
				'entities' => array( array( 'type' => 'bot_command', 'offset' => 0, 'length' => $entity_length ) ),
			),
			$bot->telegram_username()
		);

		$this->dispatcher->handle(
			$bot,
			'-100123',
			null,
			$parsed,
			array( 'message' => array( 'from' => array( 'id' => $sender_telegram_user_id ) ) )
		);
	}

	public function test_status_reports_bounded_queue_and_activity_aggregates(): void {
		$bot = $this->bots->create( 'Support Bot', 'token' );
		$this->destinations->create( $bot->id(), DestinationKind::SUPERGROUP, '-100123', null, 'Support group' );
		list( $operator_wp_id, $role ) = $this->mapped_operator();
		$this->operator_identities->create( $operator_wp_id, 21, null, 1 );

		$now = current_time( 'mysql', true );
		$this->insert_history_row( 'evt-wp4-1', 'wordpress_core', $now );
		$this->insert_history_row( 'evt-wp4-2', 'woocommerce', $now );
		$this->insert_history_row( 'evt-wp4-3', 'visitor', $now );
		$this->insert_history_row( 'evt-wp4-4', 'visitor', $now );

		try {
			$this->handle( $bot, '/status', 7, 21 );

			$text = $this->last_outbound_text();
			$this->assertNotNull( $text );
			$this->assertStringContainsString( 'Queue: 0 pending, 0 failed', $text );
			$this->assertStringContainsString( 'wordpress=1', $text );
			$this->assertStringContainsString( 'woocommerce=1', $text );
			$this->assertStringContainsString( 'visitor=2', $text );
		} finally {
			$role->remove_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );
		}
	}

	public function test_errors_reports_bounded_wordpress_core_count_and_queue_failed(): void {
		$bot = $this->bots->create( 'Support Bot', 'token' );
		$this->destinations->create( $bot->id(), DestinationKind::SUPERGROUP, '-100123', null, 'Support group' );
		list( $operator_wp_id, $role ) = $this->mapped_operator();
		$this->operator_identities->create( $operator_wp_id, 22, null, 1 );

		$now = current_time( 'mysql', true );
		$this->insert_history_row( 'evt-wp4-5', 'wordpress_core', $now );
		$this->insert_history_row( 'evt-wp4-6', 'wordpress_core', $now );
		// Outside the 24h window — must not be counted.
		$this->insert_history_row( 'evt-wp4-7', 'wordpress_core', gmdate( 'Y-m-d H:i:s', time() - ( 25 * HOUR_IN_SECONDS ) ) );

		try {
			$this->handle( $bot, '/errors', 7, 22 );

			$text = $this->last_outbound_text();
			$this->assertStringContainsString( 'WordPress errors (24h): 2', $text );
			$this->assertStringContainsString( 'Queue failed: 0', $text );
		} finally {
			$role->remove_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );
		}
	}

	public function test_visitors_reports_the_fixed_24h_count(): void {
		$bot = $this->bots->create( 'Support Bot', 'token' );
		$this->destinations->create( $bot->id(), DestinationKind::SUPERGROUP, '-100123', null, 'Support group' );
		list( $operator_wp_id, $role ) = $this->mapped_operator();
		$this->operator_identities->create( $operator_wp_id, 23, null, 1 );

		$now = current_time( 'mysql', true );
		$this->insert_history_row( 'evt-wp4-8', 'visitor', $now );
		$this->insert_history_row( 'evt-wp4-9', 'visitor', $now );
		$this->insert_history_row( 'evt-wp4-10', 'visitor', $now );

		try {
			$this->handle( $bot, '/visitors', 9, 23 );

			$this->assertSame( 'Visitor events (24h): 3', $this->last_outbound_text() );
		} finally {
			$role->remove_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );
		}
	}

	public function test_status_errors_visitors_are_general_topic_only(): void {
		$bot = $this->bots->create( 'Support Bot', 'token' );
		$this->destinations->create( $bot->id(), DestinationKind::SUPERGROUP, '-100123', null, 'Support group' );
		$conversation = $this->conversation_topic_fixture( $bot );
		list( $operator_wp_id, $role ) = $this->mapped_operator();
		$this->operator_identities->create( $operator_wp_id, 24, null, 1 );

		try {
			$parsed = CommandParser::parse(
				array(
					'text'     => '/status',
					'entities' => array( array( 'type' => 'bot_command', 'offset' => 0, 'length' => 7 ) ),
				),
				$bot->telegram_username()
			);

			$this->dispatcher->handle(
				$bot,
				'-100123',
				$conversation['thread_id'],
				$parsed,
				array( 'message' => array( 'from' => array( 'id' => 24 ) ) )
			);

			$this->assertSame( "This command isn't available here.", $this->last_outbound_text() );
		} finally {
			$role->remove_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );
		}
	}

	private function conversation_topic_fixture( $bot ): array {
		$conversations = new ConversationRepository( $this->schema_health, new CredentialVault(), new VisitorTokenGenerator() );
		$conversation  = $conversations->create( 'uuid-wp4-1', 'hash', $bot->id(), null );
		$destination   = $this->destinations->create( $bot->id(), DestinationKind::SUPERGROUP, '-100123', 77, 'Topic' );
		$conversations->mark_topic_created( $conversation->id(), 77, $destination->id() );

		return array( 'thread_id' => 77 );
	}
}
