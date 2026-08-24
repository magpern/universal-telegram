<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Telegram\Inbound;

use UniversalTelegram\Audit\AuditLogger;
use UniversalTelegram\Audit\AuditLogRepository;
use UniversalTelegram\Conversations\ChatProfileResolver;
use UniversalTelegram\Conversations\ConversationRepository;
use UniversalTelegram\Conversations\OperatorAvailabilityRepository;
use UniversalTelegram\Conversations\OperatorIdentityRepository;
use UniversalTelegram\Conversations\VisitorTokenGenerator;
use UniversalTelegram\Conversations\ConversationStatus;
use UniversalTelegram\Conversations\MessageRepository;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Events\EventHistoryRepository;
use UniversalTelegram\Events\Registry;
use UniversalTelegram\Integrations\WooCommerce\WooCommerceCommandQueryService;
use UniversalTelegram\Integrations\WooCommerce\WooCommerceSupport;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Privacy\Redactor;
use UniversalTelegram\Queue\Dispatcher;
use UniversalTelegram\Queue\QueueHealth;
use UniversalTelegram\Telegram\Commands\BotCommandDispatcher;
use UniversalTelegram\Telegram\Commands\ConfirmationStore;
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
 * M05's own narrow, additive extension of WebhookController's otherwise
 * metadata-only inbound path (M05 plan §6, docs/adr/0021): content capture
 * proceeds only when dedup, chat-identity, and known-topic-mapping all
 * hold. Every gate-failure case is asserted to be byte-for-byte identical
 * to WebhookControllerTest's own pre-M05 metadata-only assertions.
 */
final class WebhookControllerConversationRoutingTest extends WP_UnitTestCase {

	private SchemaHealth $schema_health;
	private BotProfileRepository $bots;
	private ConversationRepository $conversations;
	private MessageRepository $messages;
	private DestinationRepository $destinations;
	private OperatorIdentityRepository $operator_identities;
	private AuditLogger $audit_logger;
	private WebhookController $controller;

	public const MAPPED_SENDER_TELEGRAM_ID = 999888777;

	protected function setUp(): void {
		parent::setUp();

		$this->schema_health = new SchemaHealth();
		$vault               = new CredentialVault();
		$this->audit_logger  = new AuditLogger( $this->schema_health, new Redactor() );

		$this->bots                = new BotProfileRepository( $this->schema_health, $vault );
		$this->conversations       = new ConversationRepository( $this->schema_health, new CredentialVault(), new VisitorTokenGenerator() );
		$this->messages            = new MessageRepository( $this->schema_health, $vault );
		$this->destinations        = new DestinationRepository( $this->schema_health );
		$this->operator_identities = new OperatorIdentityRepository( $this->schema_health );
		$updates                   = new UpdateRepository( $this->schema_health );
		$verifier                  = new WebhookSecretVerifier( $this->bots, $this->audit_logger );

		$this->operator_identities->create( 1, self::MAPPED_SENDER_TELEGRAM_ID, 'opuser', 1 );

		$outbound_messages  = new OutboundMessageRepository( $this->schema_health, $vault );
		$message_dispatcher = new MessageDispatcher( $outbound_messages, new Dispatcher( $this->schema_health ) );
		$bot_commands       = new BotCommandDispatcher(
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
			$this->audit_logger
		);

		$this->controller = new WebhookController(
			$this->schema_health,
			$this->bots,
			$verifier,
			$updates,
			$this->conversations,
			$this->messages,
			new ChatProfileResolver( $this->bots, $this->destinations ),
			$this->operator_identities,
			$this->audit_logger,
			$bot_commands
		);
	}

	private function request( string $bot_uuid, string $secret, int $update_id, string $chat_id, ?int $message_thread_id, string $text, ?int $sender_telegram_user_id = self::MAPPED_SENDER_TELEGRAM_ID ): WP_REST_Request {
		$body = wp_json_encode(
			array(
				'update_id' => $update_id,
				'message'   => array_filter(
					array(
						'chat'              => array( 'id' => $chat_id ),
						'message_thread_id' => $message_thread_id,
						'text'              => $text,
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

	/**
	 * @return array{bot: \UniversalTelegram\Telegram\Configuration\BotProfile, conversation: \UniversalTelegram\Conversations\Conversation}
	 */
	private function conversation_with_a_created_topic( string $status = ConversationStatus::OPEN ): array {
		$bot = $this->bots->create( 'Support Bot', 'token' );
		$this->destinations->create( $bot->id(), DestinationKind::SUPERGROUP, '-100123', null, 'Support group' );

		$conversation = $this->conversations->create( 'uuid-inbound-1', 'hash', $bot->id(), null );
		$destination  = $this->destinations->create( $bot->id(), DestinationKind::SUPERGROUP, '-100123', 88, 'Topic' );
		$this->conversations->mark_topic_created( $conversation->id(), 88, $destination->id() );

		if ( ConversationStatus::OPEN !== $status ) {
			$this->conversations->transition( $conversation->id(), ConversationStatus::OPEN, $status );
		}

		return array(
			'bot'          => $bot,
			'conversation' => $this->conversations->find( $conversation->id() ),
		);
	}

	public function test_an_operator_reply_in_the_known_topic_is_captured_and_transitions_the_conversation(): void {
		$fixture = $this->conversation_with_a_created_topic();
		$bot     = $fixture['bot'];

		$response = $this->controller->handle_request(
			$this->request( $bot->bot_uuid(), $this->active_secret_for( $bot ), 900, '-100123', 88, 'We can help with that.' )
		);

		$this->assertSame( 200, $response->get_status() );

		$updated = $this->conversations->find( $fixture['conversation']->id() );
		$this->assertSame( ConversationStatus::WAITING_FOR_VISITOR, $updated->status() );

		$messages = $this->messages->messages_since( $fixture['conversation']->id(), 0 );
		$this->assertCount( 1, $messages );
		$this->assertSame( 'operator', $messages[0]->direction() );
		$this->assertSame( 'We can help with that.', $this->messages->decrypt( $messages[0] ) );
		$this->assertSame( self::MAPPED_SENDER_TELEGRAM_ID, $messages[0]->telegram_sender_user_id() );
	}

	public function test_an_unmapped_sender_captures_nothing_and_transitions_nothing(): void {
		$fixture = $this->conversation_with_a_created_topic();
		$bot     = $fixture['bot'];

		$response = $this->controller->handle_request(
			$this->request( $bot->bot_uuid(), $this->active_secret_for( $bot ), 950, '-100123', 88, 'I am not mapped.', 111222333 )
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array(), $this->messages->messages_since( $fixture['conversation']->id(), 0 ) );
		$this->assertSame( ConversationStatus::OPEN, $this->conversations->find( $fixture['conversation']->id() )->status() );
	}

	public function test_an_unmapped_sender_records_a_rejection_audit_entry_without_the_raw_telegram_id(): void {
		$fixture = $this->conversation_with_a_created_topic();
		$bot     = $fixture['bot'];

		$this->controller->handle_request(
			$this->request( $bot->bot_uuid(), $this->active_secret_for( $bot ), 951, '-100123', 88, 'I am not mapped.', 111222333 )
		);

		$audit_log = new AuditLogRepository( $this->schema_health );
		$entries   = array_values(
			array_filter(
				$audit_log->recent( 50 ),
				static function ( array $entry ): bool {
					return 'conversation.operator_reply.rejected_unmapped_sender' === $entry['action'];
				}
			)
		);

		$this->assertCount( 1, $entries );

		$context = (string) $entries[0]['context'];
		$this->assertStringNotContainsString( '111222333', $context );
		$this->assertStringContainsString( (string) $fixture['conversation']->id(), $context );
	}

	public function test_a_sender_with_no_from_field_captures_nothing(): void {
		$fixture = $this->conversation_with_a_created_topic();
		$bot     = $fixture['bot'];

		$response = $this->controller->handle_request(
			$this->request( $bot->bot_uuid(), $this->active_secret_for( $bot ), 952, '-100123', 88, 'No sender.', null )
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array(), $this->messages->messages_since( $fixture['conversation']->id(), 0 ) );
	}

	public function test_wrong_chat_id_captures_nothing_and_matches_the_pre_m05_metadata_only_outcome(): void {
		$fixture = $this->conversation_with_a_created_topic();
		$bot     = $fixture['bot'];

		$response = $this->controller->handle_request(
			$this->request( $bot->bot_uuid(), $this->active_secret_for( $bot ), 901, '-999999', 88, 'Wrong chat.' )
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array(), $this->messages->messages_since( $fixture['conversation']->id(), 0 ) );
		$this->assertSame( ConversationStatus::OPEN, $this->conversations->find( $fixture['conversation']->id() )->status() );
	}

	public function test_unknown_topic_captures_nothing(): void {
		$fixture = $this->conversation_with_a_created_topic();
		$bot     = $fixture['bot'];

		$response = $this->controller->handle_request(
			$this->request( $bot->bot_uuid(), $this->active_secret_for( $bot ), 902, '-100123', 999999, 'Unknown topic.' )
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array(), $this->messages->messages_since( $fixture['conversation']->id(), 0 ) );
	}

	public function test_malformed_thread_reference_no_message_thread_id_captures_nothing(): void {
		$fixture = $this->conversation_with_a_created_topic();
		$bot     = $fixture['bot'];

		$response = $this->controller->handle_request(
			$this->request( $bot->bot_uuid(), $this->active_secret_for( $bot ), 903, '-100123', null, 'No thread.' )
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array(), $this->messages->messages_since( $fixture['conversation']->id(), 0 ) );
	}

	public function test_a_duplicate_update_never_captures_a_second_message(): void {
		$fixture = $this->conversation_with_a_created_topic();
		$bot     = $fixture['bot'];
		$secret  = $this->active_secret_for( $bot );

		$this->controller->handle_request( $this->request( $bot->bot_uuid(), $secret, 904, '-100123', 88, 'First.' ) );
		$this->controller->handle_request( $this->request( $bot->bot_uuid(), $secret, 904, '-100123', 88, 'First.' ) );

		$this->assertCount( 1, $this->messages->messages_since( $fixture['conversation']->id(), 0 ) );
	}

	public function test_a_topic_still_pending_never_matches_and_captures_nothing(): void {
		$bot = $this->bots->create( 'Support Bot', 'token' );
		$this->destinations->create( $bot->id(), DestinationKind::SUPERGROUP, '-100123', null, 'Support group' );

		$conversation = $this->conversations->create( 'uuid-inbound-pending', 'hash', $bot->id(), null );
		$this->conversations->try_begin_topic_creation( $conversation->id() );

		$response = $this->controller->handle_request(
			$this->request( $bot->bot_uuid(), $this->active_secret_for( $bot ), 905, '-100123', 88, 'Too early.' )
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array(), $this->messages->messages_since( $conversation->id(), 0 ) );
	}

	public function test_same_thread_id_in_a_different_chat_never_matches(): void {
		$fixture = $this->conversation_with_a_created_topic();
		$bot     = $fixture['bot'];

		$response = $this->controller->handle_request(
			$this->request( $bot->bot_uuid(), $this->active_secret_for( $bot ), 906, '-100999', 88, 'Wrong chat.' )
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array(), $this->messages->messages_since( $fixture['conversation']->id(), 0 ) );
		$this->assertSame( 'active', $this->conversations->find( $fixture['conversation']->id() )->topic_lifecycle_state() );
	}

	public function test_forum_topic_closed_marks_unavailable_only_on_exact_tuple(): void {
		$fixture = $this->conversation_with_a_created_topic();
		$bot     = $fixture['bot'];
		$secret  = $this->active_secret_for( $bot );

		$request = new WP_REST_Request( 'POST', '/universal-telegram/v1/webhook/' . $bot->bot_uuid() );
		$request->set_header( 'X-Telegram-Bot-Api-Secret-Token', $secret );
		$request->set_url_params( array( 'bot_uuid' => $bot->bot_uuid() ) );
		$request->set_body(
			wp_json_encode(
				array(
					'update_id' => 907,
					'message'   => array(
						'message_id'         => 1,
						'chat'               => array( 'id' => '-100123' ),
						'message_thread_id'  => 88,
						'forum_topic_closed' => array(),
						'from'               => array( 'id' => self::MAPPED_SENDER_TELEGRAM_ID ),
					),
				)
			)
		);

		$this->controller->handle_request( $request );

		$fresh = $this->conversations->find( $fixture['conversation']->id() );
		$this->assertSame( 'unavailable', $fresh->topic_lifecycle_state() );
		$this->assertSame( 'telegram_topic_closed', $fresh->topic_lifecycle_code() );
		$this->assertSame( array(), $this->messages->messages_since( $fixture['conversation']->id(), 0 ) );
	}
}
