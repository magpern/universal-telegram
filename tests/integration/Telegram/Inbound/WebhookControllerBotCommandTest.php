<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Telegram\Inbound;

use UniversalTelegram\Audit\AuditLogger;
use UniversalTelegram\Audit\AuditLogRepository;
use UniversalTelegram\Conversations\ChatProfileResolver;
use UniversalTelegram\Conversations\ConversationRepository;
use UniversalTelegram\Conversations\ConversationStatus;
use UniversalTelegram\Conversations\MessageRepository;
use UniversalTelegram\Conversations\OperatorAvailabilityRepository;
use UniversalTelegram\Conversations\OperatorIdentityRepository;
use UniversalTelegram\Conversations\VisitorTokenGenerator;
use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Privacy\Redactor;
use UniversalTelegram\Queue\Dispatcher;
use UniversalTelegram\Telegram\Commands\BotCommandDispatcher;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;
use UniversalTelegram\Telegram\Configuration\DestinationKind;
use UniversalTelegram\Telegram\Configuration\DestinationRepository;
use UniversalTelegram\Telegram\Inbound\UpdateRepository;
use UniversalTelegram\Telegram\Inbound\WebhookController;
use UniversalTelegram\Telegram\Inbound\WebhookSecretVerifier;
use UniversalTelegram\Telegram\Outbound\MessageDispatcher;
use UniversalTelegram\Telegram\Outbound\OutboundMessageRepository;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * M08 WP2: the recognized-command branch WebhookController gains ahead of
 * any per-family command handler existing — authorization (two-factor,
 * merged unauthorized outcome), context resolution (General topic / known
 * conversation topic / unknown topic, the last fully silent), and that a
 * non-command message's existing reply-capture behavior (M07) is entirely
 * unchanged. No command actually executes successfully in this test file —
 * every case here is a rejection path, matching WP2's own stated scope.
 */
final class WebhookControllerBotCommandTest extends WP_UnitTestCase {

	private SchemaHealth $schema_health;
	private BotProfileRepository $bots;
	private ConversationRepository $conversations;
	private DestinationRepository $destinations;
	private OperatorIdentityRepository $operator_identities;
	private AuditLogger $audit_logger;
	private WebhookController $controller;

	protected function setUp(): void {
		parent::setUp();

		$this->schema_health = new SchemaHealth();
		$vault               = new CredentialVault();
		$this->audit_logger  = new AuditLogger( $this->schema_health, new Redactor() );

		$this->bots                = new BotProfileRepository( $this->schema_health, $vault );
		$this->conversations       = new ConversationRepository( $this->schema_health, new CredentialVault(), new VisitorTokenGenerator() );
		$messages                  = new MessageRepository( $this->schema_health, $vault );
		$this->destinations        = new DestinationRepository( $this->schema_health );
		$this->operator_identities = new OperatorIdentityRepository( $this->schema_health );
		$updates                   = new UpdateRepository( $this->schema_health );
		$verifier                  = new WebhookSecretVerifier( $this->bots, $this->audit_logger );

		$outbound_messages  = new OutboundMessageRepository( $this->schema_health, $vault );
		$message_dispatcher = new MessageDispatcher( $outbound_messages, new Dispatcher( $this->schema_health ) );

		$bot_commands = new BotCommandDispatcher(
			$this->operator_identities,
			$this->conversations,
			new ChatProfileResolver( $this->bots, $this->destinations ),
			new OperatorAvailabilityRepository( $this->schema_health ),
			new \UniversalTelegram\Queue\QueueHealth(),
			new \UniversalTelegram\Events\EventHistoryRepository( $this->schema_health, new \UniversalTelegram\Events\Registry(), new Redactor() ),
			new \UniversalTelegram\Integrations\WooCommerce\WooCommerceSupport(),
			new \UniversalTelegram\Integrations\WooCommerce\WooCommerceCommandQueryService(),
			new \UniversalTelegram\Telegram\Commands\ConfirmationStore(),
			$message_dispatcher,
			$this->audit_logger
		);

		$this->controller = new WebhookController(
			$this->schema_health,
			$this->bots,
			$verifier,
			$updates,
			$this->conversations,
			$messages,
			new ChatProfileResolver( $this->bots, $this->destinations ),
			$this->operator_identities,
			$this->audit_logger,
			$bot_commands
		);
	}

	private function command_request( string $bot_uuid, string $secret, int $update_id, string $chat_id, ?int $message_thread_id, string $text, int $entity_length, ?int $sender_telegram_user_id ): WP_REST_Request {
		$body = wp_json_encode(
			array(
				'update_id' => $update_id,
				'message'   => array_filter(
					array(
						'chat'              => array( 'id' => $chat_id ),
						'message_thread_id' => $message_thread_id,
						'text'              => $text,
						'entities'          => array(
							array(
								'type'   => 'bot_command',
								'offset' => 0,
								'length' => $entity_length,
							),
						),
						'from'              => null === $sender_telegram_user_id ? null : array( 'id' => $sender_telegram_user_id ),
					),
					static function ( $value ) {
						return null !== $value;
					}
				),
			)
		);

		$request = new WP_REST_Request( 'POST', '/universal-telegram/v1/webhook/' . $bot_uuid );
		$request->set_url_params( array( 'bot_uuid' => $bot_uuid ) );
		$request->set_header( 'X-Telegram-Bot-Api-Secret-Token', $secret );
		$request->set_body( $body );

		return $request;
	}

	private function active_secret_for( $bot ): string {
		return $this->bots->decrypt_webhook_secret( $bot )->plaintext();
	}

	private function mapped_operator_with_capability(): array {
		$operator = self::factory()->user->create();
		$role     = get_role( 'subscriber' );
		$role->add_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );

		return array( $operator, $role );
	}

	private function outbound_message_count(): int {
		global $wpdb;

		$table = $wpdb->prefix . Migrator::OUTBOUND_MESSAGES_TABLE;

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	private function bot_command_audit_entries(): array {
		$audit_log = new AuditLogRepository( $this->schema_health );

		return array_values(
			array_filter(
				$audit_log->recent( 50 ),
				static function ( array $entry ): bool {
					return 0 === strpos( $entry['action'], 'bot_command.' );
				}
			)
		);
	}

	public function test_wrong_chat_id_is_a_silent_drop_with_no_audit_and_no_reply(): void {
		$bot = $this->bots->create( 'Support Bot', 'token' );
		$this->destinations->create( $bot->id(), DestinationKind::SUPERGROUP, '-100123', null, 'Support group' );
		list( $operator_wp_id, $role ) = $this->mapped_operator_with_capability();
		$this->operator_identities->create( $operator_wp_id, 555, null, 1 );

		try {
			$response = $this->controller->handle_request(
				$this->command_request( $bot->bot_uuid(), $this->active_secret_for( $bot ), 1001, '-999999', null, '/help', 5, 555 )
			);

			$this->assertSame( 200, $response->get_status() );
			$this->assertCount( 0, $this->bot_command_audit_entries() );
			$this->assertSame( 0, $this->outbound_message_count() );
		} finally {
			$role->remove_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );
		}
	}

	public function test_unmapped_sender_is_rejected_with_no_reply_and_a_merged_audit_code(): void {
		$bot = $this->bots->create( 'Support Bot', 'token' );
		$this->destinations->create( $bot->id(), DestinationKind::SUPERGROUP, '-100123', null, 'Support group' );

		$response = $this->controller->handle_request(
			$this->command_request( $bot->bot_uuid(), $this->active_secret_for( $bot ), 1002, '-100123', null, '/help', 5, 999999 )
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 0, $this->outbound_message_count() );

		$entries = $this->bot_command_audit_entries();
		$this->assertCount( 1, $entries );
		$this->assertSame( 'bot_command.rejected_unauthorized', $entries[0]['action'] );
		$this->assertStringNotContainsString( '999999', (string) $entries[0]['context'] );
	}

	public function test_mapped_sender_without_the_capability_gets_the_identical_outcome_as_unmapped(): void {
		$bot = $this->bots->create( 'Support Bot', 'token' );
		$this->destinations->create( $bot->id(), DestinationKind::SUPERGROUP, '-100123', null, 'Support group' );

		$operator = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->operator_identities->create( $operator, 777, null, 1 );

		$response = $this->controller->handle_request(
			$this->command_request( $bot->bot_uuid(), $this->active_secret_for( $bot ), 1003, '-100123', null, '/help', 5, 777 )
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 0, $this->outbound_message_count() );

		$entries = $this->bot_command_audit_entries();
		$this->assertCount( 1, $entries );
		$this->assertSame( 'bot_command.rejected_unauthorized', $entries[0]['action'] );
	}

	public function test_unknown_topic_is_fully_silent_but_audited(): void {
		$bot = $this->bots->create( 'Support Bot', 'token' );
		$this->destinations->create( $bot->id(), DestinationKind::SUPERGROUP, '-100123', null, 'Support group' );
		list( $operator_wp_id, $role ) = $this->mapped_operator_with_capability();
		$this->operator_identities->create( $operator_wp_id, 888, null, 1 );

		try {
			$response = $this->controller->handle_request(
				$this->command_request( $bot->bot_uuid(), $this->active_secret_for( $bot ), 1004, '-100123', 424242, '/help', 5, 888 )
			);

			$this->assertSame( 200, $response->get_status() );
			$this->assertSame( 0, $this->outbound_message_count(), 'no acknowledgement is ever sent into an unknown topic' );

			$entries = $this->bot_command_audit_entries();
			$this->assertCount( 1, $entries );
			$this->assertSame( 'bot_command.rejected_wrong_context', $entries[0]['action'] );
		} finally {
			$role->remove_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );
		}
	}

	public function test_a_conversation_only_command_in_the_general_topic_is_rejected_with_an_acknowledgement(): void {
		$bot = $this->bots->create( 'Support Bot', 'token' );
		$this->destinations->create( $bot->id(), DestinationKind::SUPERGROUP, '-100123', null, 'Support group' );
		list( $operator_wp_id, $role ) = $this->mapped_operator_with_capability();
		$this->operator_identities->create( $operator_wp_id, 999, null, 1 );

		try {
			// /claim is CONTEXT_CONVERSATION-only; sent with no message_thread_id (General topic).
			$response = $this->controller->handle_request(
				$this->command_request( $bot->bot_uuid(), $this->active_secret_for( $bot ), 1005, '-100123', null, '/claim', 6, 999 )
			);

			$this->assertSame( 200, $response->get_status() );

			$entries = $this->bot_command_audit_entries();
			$this->assertCount( 1, $entries );
			$this->assertSame( 'bot_command.rejected_wrong_context', $entries[0]['action'] );

			// Unlike the unknown-topic case, a known-context mismatch does
			// send a bounded acknowledgement.
			$this->assertSame( 1, $this->outbound_message_count() );
		} finally {
			$role->remove_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );
		}
	}

	public function test_a_non_command_reply_inside_a_known_conversation_topic_is_captured_exactly_as_before(): void {
		$bot = $this->bots->create( 'Support Bot', 'token' );
		$this->destinations->create( $bot->id(), DestinationKind::SUPERGROUP, '-100123', null, 'Support group' );

		$conversation = $this->conversations->create( 'uuid-wp2-1', 'hash', $bot->id(), null );
		$destination  = $this->destinations->create( $bot->id(), DestinationKind::SUPERGROUP, '-100123', 55, 'Topic' );
		$this->conversations->mark_topic_created( $conversation->id(), 55, $destination->id() );

		list( $operator_wp_id, $role ) = $this->mapped_operator_with_capability();
		$this->operator_identities->create( $operator_wp_id, 1010, null, 1 );

		try {
			// No 'entities' key at all — an ordinary reply, not a command.
			$body = wp_json_encode(
				array(
					'update_id' => 1006,
					'message'   => array(
						'chat'              => array( 'id' => '-100123' ),
						'message_thread_id' => 55,
						'text'              => 'We can help with that.',
						'from'              => array( 'id' => 1010 ),
					),
				)
			);

			$request = new WP_REST_Request( 'POST', '/universal-telegram/v1/webhook/' . $bot->bot_uuid() );
			$request->set_url_params( array( 'bot_uuid' => $bot->bot_uuid() ) );
			$request->set_header( 'X-Telegram-Bot-Api-Secret-Token', $this->active_secret_for( $bot ) );
			$request->set_body( $body );

			$response = $this->controller->handle_request( $request );

			$this->assertSame( 200, $response->get_status() );
			$this->assertSame( ConversationStatus::WAITING_FOR_VISITOR, $this->conversations->find( $conversation->id() )->status() );
			$this->assertCount( 0, $this->bot_command_audit_entries() );
		} finally {
			$role->remove_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );
		}
	}

	public function test_a_near_miss_slash_message_inside_a_conversation_topic_falls_through_to_reply_capture(): void {
		$bot = $this->bots->create( 'Support Bot', 'token' );
		$this->destinations->create( $bot->id(), DestinationKind::SUPERGROUP, '-100123', null, 'Support group' );

		$conversation = $this->conversations->create( 'uuid-wp2-2', 'hash', $bot->id(), null );
		$destination  = $this->destinations->create( $bot->id(), DestinationKind::SUPERGROUP, '-100123', 66, 'Topic' );
		$this->conversations->mark_topic_created( $conversation->id(), 66, $destination->id() );

		list( $operator_wp_id, $role ) = $this->mapped_operator_with_capability();
		$this->operator_identities->create( $operator_wp_id, 1111, null, 1 );

		try {
			$body = wp_json_encode(
				array(
					'update_id' => 1007,
					'message'   => array(
						'chat'              => array( 'id' => '-100123' ),
						'message_thread_id' => 66,
						'text'              => '/pricing is on our website',
						'entities'          => array(
							array(
								'type'   => 'bot_command',
								'offset' => 0,
								'length' => 8,
							),
						),
						'from'              => array( 'id' => 1111 ),
					),
				)
			);

			$request = new WP_REST_Request( 'POST', '/universal-telegram/v1/webhook/' . $bot->bot_uuid() );
			$request->set_url_params( array( 'bot_uuid' => $bot->bot_uuid() ) );
			$request->set_header( 'X-Telegram-Bot-Api-Secret-Token', $this->active_secret_for( $bot ) );
			$request->set_body( $body );

			$response = $this->controller->handle_request( $request );

			$this->assertSame( 200, $response->get_status() );
			// '/pricing' is not an allow-listed command word, so it falls
			// through to reply capture exactly as any other operator text.
			$this->assertSame( ConversationStatus::WAITING_FOR_VISITOR, $this->conversations->find( $conversation->id() )->status() );
		} finally {
			$role->remove_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );
		}
	}
}
