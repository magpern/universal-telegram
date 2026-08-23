<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Telegram\Commands;

use UniversalTelegram\Audit\AuditLogger;
use UniversalTelegram\Conversations\ChatProfileResolver;
use UniversalTelegram\Conversations\ConversationRepository;
use UniversalTelegram\Conversations\OperatorAvailability;
use UniversalTelegram\Conversations\OperatorAvailabilityRepository;
use UniversalTelegram\Conversations\OperatorIdentityRepository;
use UniversalTelegram\Conversations\VisitorTokenGenerator;
use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Privacy\Redactor;
use UniversalTelegram\Queue\Dispatcher;
use UniversalTelegram\Telegram\Commands\BotCommandDispatcher;
use UniversalTelegram\Telegram\Commands\CommandParser;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;
use UniversalTelegram\Telegram\Configuration\DestinationKind;
use UniversalTelegram\Telegram\Configuration\DestinationRepository;
use UniversalTelegram\Telegram\Outbound\MessageDispatcher;
use UniversalTelegram\Telegram\Outbound\OutboundMessageRepository;
use WP_UnitTestCase;

/**
 * M08 WP3: /help, /whoami, /conversations — the first three commands with
 * real, non-stub output.
 */
final class BotCommandDispatcherFamilyATest extends WP_UnitTestCase {

	private SchemaHealth $schema_health;
	private BotProfileRepository $bots;
	private ConversationRepository $conversations;
	private DestinationRepository $destinations;
	private OperatorIdentityRepository $operator_identities;
	private OperatorAvailabilityRepository $availability;
	private OutboundMessageRepository $outbound_messages;
	private BotCommandDispatcher $dispatcher;

	protected function setUp(): void {
		parent::setUp();

		$this->schema_health = new SchemaHealth();
		$vault                = new CredentialVault();
		$audit                = new AuditLogger( $this->schema_health, new Redactor() );

		$this->bots                = new BotProfileRepository( $this->schema_health, $vault );
		$this->conversations       = new ConversationRepository( $this->schema_health, new CredentialVault(), new VisitorTokenGenerator() );
		$this->destinations        = new DestinationRepository( $this->schema_health );
		$this->operator_identities = new OperatorIdentityRepository( $this->schema_health );
		$this->availability        = new OperatorAvailabilityRepository( $this->schema_health );

		$this->outbound_messages = new OutboundMessageRepository( $this->schema_health, $vault );
		$message_dispatcher      = new MessageDispatcher( $this->outbound_messages, new Dispatcher( $this->schema_health ) );

		$this->dispatcher = new BotCommandDispatcher(
			$this->operator_identities,
			$this->conversations,
			new ChatProfileResolver( $this->bots, $this->destinations ),
			$this->availability,
			new \UniversalTelegram\Queue\QueueHealth(),
			new \UniversalTelegram\Events\EventHistoryRepository( $this->schema_health, new \UniversalTelegram\Events\Registry(), new Redactor() ),
			new \UniversalTelegram\Integrations\WooCommerce\WooCommerceSupport(),
			new \UniversalTelegram\Integrations\WooCommerce\WooCommerceCommandQueryService(),
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

	private function last_outbound_text(): ?string {
		global $wpdb;

		$table = $wpdb->prefix . \UniversalTelegram\Persistence\Migrator::OUTBOUND_MESSAGES_TABLE;
		$id    = $wpdb->get_var( "SELECT id FROM {$table} ORDER BY id DESC LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( null === $id ) {
			return null;
		}

		$message = $this->outbound_messages->find( (int) $id );

		return null === $message ? null : $this->outbound_messages->decrypt_body( $message )->plaintext();
	}

	private function handle( $bot, ?string $chat_id, ?int $thread_id, string $command_text, int $entity_length, int $sender_telegram_user_id ): void {
		$parsed = CommandParser::parse(
			array(
				'text'     => $command_text,
				'entities' => array( array( 'type' => 'bot_command', 'offset' => 0, 'length' => $entity_length ) ),
			),
			$bot->telegram_username()
		);

		$this->dispatcher->handle(
			$bot,
			$chat_id,
			$thread_id,
			$parsed,
			array( 'message' => array( 'from' => array( 'id' => $sender_telegram_user_id ) ) )
		);
	}

	public function test_help_in_the_general_topic_lists_only_general_and_any_context_commands(): void {
		$bot = $this->bots->create( 'Support Bot', 'token' );
		$this->destinations->create( $bot->id(), DestinationKind::SUPERGROUP, '-100123', null, 'Support group' );
		list( $operator_wp_id, $role ) = $this->mapped_operator();
		$this->operator_identities->create( $operator_wp_id, 1, null, 1 );

		try {
			$this->handle( $bot, '-100123', null, '/help', 5, 1 );

			$text = $this->last_outbound_text();
			$this->assertNotNull( $text );
			$this->assertStringContainsString( '/help', $text );
			$this->assertStringContainsString( '/status', $text );
			$this->assertStringNotContainsString( '/claim', $text );
		} finally {
			$role->remove_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );
		}
	}

	public function test_help_in_a_conversation_topic_lists_only_conversation_and_any_context_commands(): void {
		$bot = $this->bots->create( 'Support Bot', 'token' );
		$this->destinations->create( $bot->id(), DestinationKind::SUPERGROUP, '-100123', null, 'Support group' );
		$conversation = $this->conversations->create( 'uuid-help-1', 'hash', $bot->id(), null );
		$destination  = $this->destinations->create( $bot->id(), DestinationKind::SUPERGROUP, '-100123', 40, 'Topic' );
		$this->conversations->mark_topic_created( $conversation->id(), 40, $destination->id() );

		list( $operator_wp_id, $role ) = $this->mapped_operator();
		$this->operator_identities->create( $operator_wp_id, 2, null, 1 );

		try {
			$this->handle( $bot, '-100123', 40, '/help', 5, 2 );

			$text = $this->last_outbound_text();
			$this->assertNotNull( $text );
			$this->assertStringContainsString( '/claim', $text );
			$this->assertStringNotContainsString( '/status', $text );
		} finally {
			$role->remove_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );
		}
	}

	public function test_whoami_reports_display_name_and_availability(): void {
		$bot = $this->bots->create( 'Support Bot', 'token' );
		$this->destinations->create( $bot->id(), DestinationKind::SUPERGROUP, '-100123', null, 'Support group' );
		list( $operator_wp_id, $role ) = $this->mapped_operator();
		wp_update_user( array( 'ID' => $operator_wp_id, 'display_name' => 'Alex Operator' ) );
		$this->operator_identities->create( $operator_wp_id, 3, null, 1 );
		$this->availability->set_state( $operator_wp_id, OperatorAvailability::AVAILABLE, $operator_wp_id );

		try {
			$this->handle( $bot, '-100123', null, '/whoami', 7, 3 );

			$text = $this->last_outbound_text();
			$this->assertStringContainsString( 'Alex Operator', $text );
			$this->assertStringContainsString( OperatorAvailability::AVAILABLE, $text );
		} finally {
			$role->remove_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );
		}
	}

	public function test_whoami_with_no_availability_row_reports_offline(): void {
		$bot = $this->bots->create( 'Support Bot', 'token' );
		$this->destinations->create( $bot->id(), DestinationKind::SUPERGROUP, '-100123', null, 'Support group' );
		list( $operator_wp_id, $role ) = $this->mapped_operator();
		$this->operator_identities->create( $operator_wp_id, 4, null, 1 );

		try {
			$this->handle( $bot, '-100123', null, '/whoami', 7, 4 );

			$text = $this->last_outbound_text();
			$this->assertStringContainsString( OperatorAvailability::OFFLINE, $text );
		} finally {
			$role->remove_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );
		}
	}

	public function test_conversations_lists_open_conversations_capped_at_ten_with_no_visitor_content(): void {
		$bot = $this->bots->create( 'Support Bot', 'token' );
		$this->destinations->create( $bot->id(), DestinationKind::SUPERGROUP, '-100123', null, 'Support group' );

		for ( $i = 0; $i < 12; $i++ ) {
			$conversation = $this->conversations->create( 'uuid-list-' . $i, 'hash', $bot->id(), null );
			$destination  = $this->destinations->create( $bot->id(), DestinationKind::SUPERGROUP, '-100123', 100 + $i, 'Topic ' . $i );
			$this->conversations->mark_topic_created( $conversation->id(), 100 + $i, $destination->id() );
		}

		list( $operator_wp_id, $role ) = $this->mapped_operator();
		$this->operator_identities->create( $operator_wp_id, 5, null, 1 );

		try {
			$this->handle( $bot, '-100123', null, '/conversations', 14, 5 );

			$text = $this->last_outbound_text();
			$this->assertNotNull( $text );
			$this->assertSame( 10, substr_count( $text, ' — ' . \UniversalTelegram\Conversations\ConversationStatus::OPEN ) );
		} finally {
			$role->remove_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );
		}
	}

	public function test_conversations_with_none_open_reports_a_fixed_message(): void {
		$bot = $this->bots->create( 'Support Bot', 'token' );
		$this->destinations->create( $bot->id(), DestinationKind::SUPERGROUP, '-100123', null, 'Support group' );
		list( $operator_wp_id, $role ) = $this->mapped_operator();
		$this->operator_identities->create( $operator_wp_id, 6, null, 1 );

		try {
			$this->handle( $bot, '-100123', null, '/conversations', 14, 6 );

			$this->assertSame( 'No open conversations.', $this->last_outbound_text() );
		} finally {
			$role->remove_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );
		}
	}
}
