<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Telegram\Commands;

use UniversalTelegram\Audit\AuditLogger;
use UniversalTelegram\Audit\AuditLogRepository;
use UniversalTelegram\Conversations\ChatProfileResolver;
use UniversalTelegram\Conversations\ConversationRepository;
use UniversalTelegram\Conversations\ConversationStatus;
use UniversalTelegram\Conversations\OperatorAvailability;
use UniversalTelegram\Conversations\OperatorAvailabilityRepository;
use UniversalTelegram\Conversations\OperatorIdentityRepository;
use UniversalTelegram\Conversations\VisitorTokenGenerator;
use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Events\EventHistoryRepository;
use UniversalTelegram\Events\Registry;
use UniversalTelegram\Integrations\WooCommerce\WooCommerceCommandQueryService;
use UniversalTelegram\Integrations\WooCommerce\WooCommerceSupport;
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
 * M08 WP6: /here, /presence, /claim, /release, /resolve, /reopen, /confirm
 * — the confirmation-gated conversation-workflow commands.
 */
final class BotCommandDispatcherFamilyFTest extends WP_UnitTestCase {

	private SchemaHealth $schema_health;
	private BotProfileRepository $bots;
	private ConversationRepository $conversations;
	private DestinationRepository $destinations;
	private OperatorIdentityRepository $operator_identities;
	private OperatorAvailabilityRepository $availability;
	private OutboundMessageRepository $outbound_messages;
	private AuditLogger $audit;
	private BotCommandDispatcher $dispatcher;

	protected function setUp(): void {
		parent::setUp();

		$this->schema_health = new SchemaHealth();
		$vault               = new CredentialVault();
		$this->audit         = new AuditLogger( $this->schema_health, new Redactor() );

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
			new QueueHealth(),
			new EventHistoryRepository( $this->schema_health, new Registry(), new Redactor() ),
			new WooCommerceSupport(),
			new WooCommerceCommandQueryService(),
			new ConfirmationStore(),
			$message_dispatcher,
			$this->audit
		);
	}

	private function mapped_operator(): array {
		$operator = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$role     = get_role( 'subscriber' );
		$role->add_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );

		return array( $operator, $role );
	}

	/**
	 * @return array{bot: \UniversalTelegram\Telegram\Configuration\BotProfile, conversation: \UniversalTelegram\Conversations\Conversation, thread_id: int}
	 */
	private function conversation_fixture( string $status = ConversationStatus::OPEN, ?int $assigned_operator_id = null ): array {
		$bot = $this->bots->create( 'Support Bot', 'token' );
		$this->destinations->create( $bot->id(), DestinationKind::SUPERGROUP, '-100123', null, 'Support group' );

		$conversation = $this->conversations->create( 'uuid-wp6-' . wp_generate_password( 8, false ), 'hash', $bot->id(), null );
		$thread_id    = random_int( 1000, 999999 );
		$destination  = $this->destinations->create( $bot->id(), DestinationKind::SUPERGROUP, '-100123', $thread_id, 'Topic' );
		$this->conversations->mark_topic_created( $conversation->id(), $thread_id, $destination->id() );

		if ( ConversationStatus::OPEN !== $status ) {
			$this->conversations->transition( $conversation->id(), ConversationStatus::OPEN, $status );
		}

		if ( null !== $assigned_operator_id ) {
			$this->conversations->assign_with_expected( $conversation->id(), null, $assigned_operator_id );
		}

		return array(
			'bot'          => $bot,
			'conversation' => $this->conversations->find( $conversation->id() ),
			'thread_id'    => $thread_id,
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

	private function audit_entries_for( string $action ): array {
		$audit_log = new AuditLogRepository( $this->schema_health );

		return array_values(
			array_filter(
				$audit_log->recent( 50 ),
				static function ( array $entry ) use ( $action ): bool {
					return $action === $entry['action'];
				}
			)
		);
	}

	private function send( $bot, ?int $thread_id, string $command_text, int $entity_length, int $sender_telegram_user_id ): void {
		$parsed = CommandParser::parse(
			array(
				'text'     => $command_text,
				'entities' => array(
					array(
						'type'   => 'bot_command',
						'offset' => 0,
						'length' => $entity_length,
					),
				),
			),
			$bot->telegram_username()
		);

		$this->dispatcher->handle(
			$bot,
			'-100123',
			$thread_id,
			$parsed,
			array( 'message' => array( 'from' => array( 'id' => $sender_telegram_user_id ) ) )
		);
	}

	public function test_here_shows_short_reference_status_and_unassigned(): void {
		$fixture                       = $this->conversation_fixture();
		list( $operator_wp_id, $role ) = $this->mapped_operator();
		$this->operator_identities->create( $operator_wp_id, 30, null, 1 );

		try {
			$this->send( $fixture['bot'], $fixture['thread_id'], '/here', 5, 30 );

			$text = $this->last_outbound_text();
			$this->assertStringContainsString( 'Status: ' . ConversationStatus::OPEN, $text );
			$this->assertStringContainsString( 'Assigned: unassigned', $text );
		} finally {
			$role->remove_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );
		}
	}

	public function test_presence_sets_own_availability_and_audits(): void {
		$fixture                       = $this->conversation_fixture();
		list( $operator_wp_id, $role ) = $this->mapped_operator();
		$this->operator_identities->create( $operator_wp_id, 31, null, 1 );

		try {
			$this->send( $fixture['bot'], null, '/presence available', 9, 31 );

			$this->assertSame( OperatorAvailability::AVAILABLE, $this->availability->find_for_operator( $operator_wp_id )->state() );
			$this->assertCount( 1, $this->audit_entries_for( 'conversation.operator_availability.set' ) );
		} finally {
			$role->remove_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );
		}
	}

	public function test_claim_assigns_and_is_rejected_when_busy(): void {
		$fixture                       = $this->conversation_fixture();
		list( $operator_wp_id, $role ) = $this->mapped_operator();
		$this->operator_identities->create( $operator_wp_id, 32, null, 1 );
		$this->availability->set_state( $operator_wp_id, OperatorAvailability::BUSY, $operator_wp_id );

		try {
			$this->send( $fixture['bot'], $fixture['thread_id'], '/claim', 6, 32 );
			$this->assertStringContainsString( 'busy or offline', (string) $this->last_outbound_text() );
			$this->assertNull( $this->conversations->find( $fixture['conversation']->id() )->assigned_operator_id() );

			$this->availability->set_state( $operator_wp_id, OperatorAvailability::AVAILABLE, $operator_wp_id );
			$this->send( $fixture['bot'], $fixture['thread_id'], '/claim', 6, 32 );

			$this->assertSame( 'Conversation claimed.', $this->last_outbound_text() );
			$this->assertSame( $operator_wp_id, $this->conversations->find( $fixture['conversation']->id() )->assigned_operator_id() );
			$this->assertCount( 1, $this->audit_entries_for( 'conversation.assignment.set' ) );
		} finally {
			$role->remove_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );
		}
	}

	public function test_release_requires_being_the_current_assignee(): void {
		list( $assignee_wp_id, $assignee_role ) = $this->mapped_operator();
		$fixture                                = $this->conversation_fixture( ConversationStatus::OPEN, $assignee_wp_id );

		list( $bystander_wp_id, $bystander_role ) = $this->mapped_operator();
		$this->operator_identities->create( $assignee_wp_id, 33, null, 1 );
		$this->operator_identities->create( $bystander_wp_id, 34, null, 1 );

		try {
			$this->send( $fixture['bot'], $fixture['thread_id'], '/release', 8, 34 );
			$this->assertStringContainsString( 'Only the assigned operator', (string) $this->last_outbound_text() );
			$this->assertSame( $assignee_wp_id, $this->conversations->find( $fixture['conversation']->id() )->assigned_operator_id() );

			$this->send( $fixture['bot'], $fixture['thread_id'], '/release', 8, 33 );
			$this->assertSame( 'Conversation released.', $this->last_outbound_text() );
			$this->assertNull( $this->conversations->find( $fixture['conversation']->id() )->assigned_operator_id() );
		} finally {
			$assignee_role->remove_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );
			$bystander_role->remove_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );
		}
	}

	public function test_resolve_requires_confirmation_before_transitioning(): void {
		list( $operator_wp_id, $role ) = $this->mapped_operator();
		$fixture                       = $this->conversation_fixture( ConversationStatus::OPEN, $operator_wp_id );
		$this->operator_identities->create( $operator_wp_id, 35, null, 1 );

		try {
			$this->send( $fixture['bot'], $fixture['thread_id'], '/resolve', 8, 35 );

			$this->assertStringContainsString( '/confirm', (string) $this->last_outbound_text() );
			$this->assertSame( ConversationStatus::OPEN, $this->conversations->find( $fixture['conversation']->id() )->status(), 'no transition before /confirm' );
			$this->assertCount( 0, $this->audit_entries_for( 'conversation.status.resolved' ) );

			$this->send( $fixture['bot'], $fixture['thread_id'], '/confirm', 8, 35 );

			$this->assertSame( ConversationStatus::RESOLVED, $this->conversations->find( $fixture['conversation']->id() )->status() );
			$this->assertSame( 'Conversation resolved.', $this->last_outbound_text() );
			$this->assertCount( 1, $this->audit_entries_for( 'conversation.status.resolved' ) );
		} finally {
			$role->remove_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );
		}
	}

	public function test_confirm_is_single_use_a_duplicate_send_is_a_no_op(): void {
		list( $operator_wp_id, $role ) = $this->mapped_operator();
		$fixture                       = $this->conversation_fixture( ConversationStatus::OPEN, $operator_wp_id );
		$this->operator_identities->create( $operator_wp_id, 36, null, 1 );

		try {
			$this->send( $fixture['bot'], $fixture['thread_id'], '/resolve', 8, 36 );
			$this->send( $fixture['bot'], $fixture['thread_id'], '/confirm', 8, 36 );
			$this->assertSame( 'Conversation resolved.', $this->last_outbound_text() );

			$this->send( $fixture['bot'], $fixture['thread_id'], '/confirm', 8, 36 );

			$this->assertStringContainsString( 'No pending confirmation', (string) $this->last_outbound_text() );
			$this->assertCount( 1, $this->audit_entries_for( 'conversation.status.resolved' ), 'the duplicate /confirm must not resolve twice' );
		} finally {
			$role->remove_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );
		}
	}

	public function test_confirm_expiry_after_the_ttl_reports_no_pending_confirmation(): void {
		list( $operator_wp_id, $role ) = $this->mapped_operator();
		$fixture                       = $this->conversation_fixture( ConversationStatus::OPEN, $operator_wp_id );
		$this->operator_identities->create( $operator_wp_id, 37, null, 1 );

		try {
			$this->send( $fixture['bot'], $fixture['thread_id'], '/resolve', 8, 37 );

			$key = 'ut_telegram_cmd_confirm_' . $fixture['bot']->id() . '_' . $fixture['conversation']->id() . '_' . $operator_wp_id;
			update_option( '_transient_timeout_' . $key, time() - 10 );

			$this->send( $fixture['bot'], $fixture['thread_id'], '/confirm', 8, 37 );

			$this->assertStringContainsString( 'No pending confirmation', (string) $this->last_outbound_text() );
			$this->assertSame( ConversationStatus::OPEN, $this->conversations->find( $fixture['conversation']->id() )->status() );
		} finally {
			$role->remove_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );
		}
	}

	public function test_confirm_from_a_different_mapped_operator_does_not_match(): void {
		list( $operator_wp_id, $role )    = $this->mapped_operator();
		$fixture                          = $this->conversation_fixture( ConversationStatus::OPEN, $operator_wp_id );
		list( $other_wp_id, $other_role ) = $this->mapped_operator();
		$this->operator_identities->create( $operator_wp_id, 38, null, 1 );
		$this->operator_identities->create( $other_wp_id, 39, null, 1 );

		try {
			$this->send( $fixture['bot'], $fixture['thread_id'], '/resolve', 8, 38 );

			// A different mapped operator can't confirm someone else's
			// pending request even inside the same topic — the
			// confirmation key is scoped to the original requester's own
			// wp_user_id, so this operator's own consume() attempt simply
			// finds nothing.
			$this->send( $fixture['bot'], $fixture['thread_id'], '/confirm', 8, 39 );

			$this->assertSame( ConversationStatus::OPEN, $this->conversations->find( $fixture['conversation']->id() )->status() );
		} finally {
			$role->remove_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );
			$other_role->remove_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );
		}
	}

	public function test_confirm_in_a_different_conversation_topic_does_not_match(): void {
		list( $operator_wp_id, $role ) = $this->mapped_operator();
		$fixture_a                     = $this->conversation_fixture( ConversationStatus::OPEN, $operator_wp_id );
		$this->operator_identities->create( $operator_wp_id, 40, null, 1 );

		// A second conversation, same bot, same operator, assigned too.
		$conversation_b = $this->conversations->create( 'uuid-wp6-other', 'hash', $fixture_a['bot']->id(), null );
		$thread_b       = random_int( 1000, 999999 );
		$destination_b  = $this->destinations->create( $fixture_a['bot']->id(), DestinationKind::SUPERGROUP, '-100123', $thread_b, 'Topic B' );
		$this->conversations->mark_topic_created( $conversation_b->id(), $thread_b, $destination_b->id() );
		$this->conversations->assign_with_expected( $conversation_b->id(), null, $operator_wp_id );

		try {
			$this->send( $fixture_a['bot'], $fixture_a['thread_id'], '/resolve', 8, 40 );

			// /confirm sent into conversation B never matches conversation
			// A's pending entry — the key is scoped per-conversation.
			$this->send( $fixture_a['bot'], $thread_b, '/confirm', 8, 40 );

			$this->assertSame( ConversationStatus::OPEN, $this->conversations->find( $fixture_a['conversation']->id() )->status() );
		} finally {
			$role->remove_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );
		}
	}

	public function test_state_drift_between_request_and_confirm_is_idempotent_safe(): void {
		list( $operator_wp_id, $role ) = $this->mapped_operator();
		$fixture                       = $this->conversation_fixture( ConversationStatus::OPEN, $operator_wp_id );
		$this->operator_identities->create( $operator_wp_id, 41, null, 1 );

		try {
			$this->send( $fixture['bot'], $fixture['thread_id'], '/resolve', 8, 41 );

			// Drift: the conversation is unassigned via the same repository
			// call the Hub itself uses, between request and confirm.
			$this->conversations->assign_with_expected( $fixture['conversation']->id(), $operator_wp_id, null );

			$this->send( $fixture['bot'], $fixture['thread_id'], '/confirm', 8, 41 );

			$this->assertStringContainsString( 'No longer eligible', (string) $this->last_outbound_text() );
			$this->assertSame( ConversationStatus::OPEN, $this->conversations->find( $fixture['conversation']->id() )->status(), 'never a stale write' );
		} finally {
			$role->remove_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );
		}
	}

	public function test_reopen_requires_the_assignee_a_bystander_is_rejected_outright(): void {
		list( $assignee_wp_id, $assignee_role ) = $this->mapped_operator();
		$fixture                                = $this->conversation_fixture( ConversationStatus::RESOLVED, $assignee_wp_id );

		list( $bystander_wp_id, $bystander_role ) = $this->mapped_operator();
		$this->operator_identities->create( $assignee_wp_id, 42, null, 1 );
		$this->operator_identities->create( $bystander_wp_id, 43, null, 1 );

		try {
			$this->send( $fixture['bot'], $fixture['thread_id'], '/reopen', 7, 43 );

			$this->assertStringContainsString( 'Only the assigned operator', (string) $this->last_outbound_text() );
			$this->assertSame( ConversationStatus::RESOLVED, $this->conversations->find( $fixture['conversation']->id() )->status(), 'never reaches a confirmation prompt' );
		} finally {
			$assignee_role->remove_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );
			$bystander_role->remove_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );
		}
	}

	public function test_reopen_on_an_unassigned_resolved_conversation_is_rejected_for_every_operator(): void {
		$fixture                       = $this->conversation_fixture( ConversationStatus::RESOLVED, null );
		list( $operator_wp_id, $role ) = $this->mapped_operator();
		$this->operator_identities->create( $operator_wp_id, 44, null, 1 );

		try {
			$this->send( $fixture['bot'], $fixture['thread_id'], '/reopen', 7, 44 );

			$this->assertStringContainsString( 'Only the assigned operator', (string) $this->last_outbound_text() );
			$this->assertSame( ConversationStatus::RESOLVED, $this->conversations->find( $fixture['conversation']->id() )->status() );
		} finally {
			$role->remove_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );
		}
	}

	public function test_reopen_by_the_assignee_requires_confirmation_then_transitions(): void {
		list( $operator_wp_id, $role ) = $this->mapped_operator();
		$fixture                       = $this->conversation_fixture( ConversationStatus::RESOLVED, $operator_wp_id );
		$this->operator_identities->create( $operator_wp_id, 45, null, 1 );

		try {
			$this->send( $fixture['bot'], $fixture['thread_id'], '/reopen', 7, 45 );
			$this->assertStringContainsString( '/confirm', (string) $this->last_outbound_text() );
			$this->assertSame( ConversationStatus::RESOLVED, $this->conversations->find( $fixture['conversation']->id() )->status() );

			$this->send( $fixture['bot'], $fixture['thread_id'], '/confirm', 8, 45 );

			$this->assertSame( ConversationStatus::OPEN, $this->conversations->find( $fixture['conversation']->id() )->status() );
			$this->assertCount( 1, $this->audit_entries_for( 'conversation.status.reopened' ) );
		} finally {
			$role->remove_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );
		}
	}
}
