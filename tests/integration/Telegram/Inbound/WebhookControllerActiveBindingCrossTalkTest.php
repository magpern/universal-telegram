<?php
/**
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Tests\Integration\Telegram\Inbound;

use UniversalTelegram\Audit\AuditLogger;
use UniversalTelegram\Conversations\ChatProfileResolver;
use UniversalTelegram\Conversations\ConversationRepository;
use UniversalTelegram\Conversations\OperatorAvailabilityRepository;
use UniversalTelegram\Conversations\OperatorIdentityRepository;
use UniversalTelegram\Conversations\VisitorTokenGenerator;
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
use UniversalTelegram\SupportChatAdapter\ChannelBinding;
use UniversalTelegram\SupportChatAdapter\ChannelBindingRepository;
use UniversalTelegram\SupportChatAdapter\Inbound\SupportChatContractClient;
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
 * ADR-0042 §5: the `maybe_mark_topic_unavailable()` live-webhook
 * cross-talk fix. When an active Support Chat binding exists for a topic,
 * a `forum_topic_closed`/`forum_topic_deleted` service message must never
 * mutate the legacy conversation's `topic_lifecycle_state` — it must be
 * dispatched via the adapter's existing `report_channel_unavailable`
 * Contract call instead (fail-closed "claimed regardless", per this
 * method's own docblock). When no active binding exists, existing legacy
 * behavior — proven by `WebhookControllerConversationRoutingTest`'s own
 * `test_forum_topic_closed_marks_unavailable_only_on_exact_tuple` — is
 * retained unchanged; this file does not re-prove that negative case,
 * only the new positive one this ADR adds.
 */
final class WebhookControllerActiveBindingCrossTalkTest extends WP_UnitTestCase {

	private const MAPPED_SENDER_TELEGRAM_ID = 555;

	private BotProfileRepository $bots;
	private ConversationRepository $conversations;
	private DestinationRepository $destinations;
	private ChannelBindingRepository $bindings;
	private WebhookController $controller;

	protected function setUp(): void {
		parent::setUp();

		$schema_health = new SchemaHealth();
		$vault         = new CredentialVault();
		$audit_logger  = new AuditLogger( $schema_health, new Redactor() );

		$this->bots          = new BotProfileRepository( $schema_health, $vault );
		$updates             = new UpdateRepository( $schema_health );
		$this->conversations = new ConversationRepository( $schema_health, new CredentialVault(), new VisitorTokenGenerator() );
		$this->destinations  = new DestinationRepository( $schema_health );
		$this->bindings      = new ChannelBindingRepository( $schema_health );
		$messages            = new MessageRepository( $schema_health, $vault );
		$verifier            = new WebhookSecretVerifier( $this->bots, $audit_logger );

		$outbound_messages  = new OutboundMessageRepository( $schema_health, $vault );
		$message_dispatcher = new MessageDispatcher( $outbound_messages, new Dispatcher( $schema_health ) );
		$bot_commands       = new BotCommandDispatcher(
			new OperatorIdentityRepository( $schema_health ),
			$this->conversations,
			new ChatProfileResolver( $this->bots, $this->destinations ),
			new OperatorAvailabilityRepository( $schema_health ),
			new QueueHealth(),
			new EventHistoryRepository( $schema_health, new Registry(), new Redactor() ),
			new WooCommerceSupport(),
			new WooCommerceCommandQueryService(),
			new ConfirmationStore(),
			$message_dispatcher,
			$audit_logger
		);

		$this->controller = new WebhookController(
			$schema_health,
			$this->bots,
			$verifier,
			$updates,
			$this->conversations,
			$messages,
			new ChatProfileResolver( $this->bots, $this->destinations ),
			new OperatorIdentityRepository( $schema_health ),
			$audit_logger,
			$bot_commands,
			1048576,
			null, // adapter_bridge, unrelated to this fix.
			null, // quiescence gate, unrelated to this fix.
			$this->bindings,
			new SupportChatContractClient() // Fail-closed by default — proves "claimed regardless" (§5).
		);
	}

	private function request( string $bot_uuid, string $secret, int $update_id, string $chat_id, int $message_thread_id, bool $deleted = false ): WP_REST_Request {
		$key  = $deleted ? 'forum_topic_deleted' : 'forum_topic_closed';
		$body = wp_json_encode(
			array(
				'update_id' => $update_id,
				'message'   => array(
					'chat'              => array( 'id' => $chat_id ),
					'message_thread_id' => $message_thread_id,
					$key                => array(),
				),
			)
		);

		$request = new WP_REST_Request( 'POST', '/universal-telegram/v1/webhook/' . $bot_uuid );
		$request->set_url_params( array( 'bot_uuid' => $bot_uuid ) );
		$request->set_header( 'X-Telegram-Bot-Api-Secret-Token', $secret );
		$request->set_body( (string) $body );

		return $request;
	}

	private function active_secret_for( $bot ): string {
		return $this->bots->decrypt_webhook_secret( $bot )->plaintext();
	}

	public function test_active_binding_topic_forum_topic_closed_never_mutates_legacy_conversation(): void {
		$bot = $this->bots->create( 'Cross-Talk Bot', 'token' );
		$this->destinations->create( $bot->id(), DestinationKind::SUPERGROUP, '-100999', null, 'Support group' );

		$conversation = $this->conversations->create( 'uuid-crosstalk-1', 'hash', $bot->id(), null );
		$destination  = $this->destinations->create( $bot->id(), DestinationKind::SUPERGROUP, '-100999', 77, 'Topic' );
		$this->conversations->mark_topic_created( $conversation->id(), 77, $destination->id() );

		$this->assertSame( 'active', $this->conversations->find( $conversation->id() )->topic_lifecycle_state() );

		// The topic is now cutover-active: a real binding exists for the
		// same (bot_id, telegram_topic_id), status active.
		$binding = $this->bindings->create(
			wp_generate_uuid4(),
			wp_generate_uuid4(),
			'ensure-crosstalk-1',
			$bot->id(),
			$destination->id(),
			77,
			ChannelBinding::STATUS_ACTIVE
		);
		$this->assertNotNull( $binding );

		$response = $this->controller->handle_request(
			$this->request( $bot->bot_uuid(), $this->active_secret_for( $bot ), 901, '-100999', 77 )
		);

		$this->assertSame( 200, $response->get_status() );

		$fresh = $this->conversations->find( $conversation->id() );
		$this->assertSame(
			'active',
			$fresh->topic_lifecycle_state(),
			'An active-binding topic must never have its legacy conversation mutated by a service message — it must be routed to the adapter Contract path instead.'
		);
	}

	public function test_no_active_binding_topic_still_marks_legacy_conversation_unavailable(): void {
		$bot = $this->bots->create( 'No Binding Bot', 'token' );
		$this->destinations->create( $bot->id(), DestinationKind::SUPERGROUP, '-100998', null, 'Support group' );

		$conversation = $this->conversations->create( 'uuid-crosstalk-2', 'hash', $bot->id(), null );
		$destination  = $this->destinations->create( $bot->id(), DestinationKind::SUPERGROUP, '-100998', 78, 'Topic' );
		$this->conversations->mark_topic_created( $conversation->id(), 78, $destination->id() );

		// No binding at all for this topic — existing legacy behavior
		// must be retained unchanged.
		$response = $this->controller->handle_request(
			$this->request( $bot->bot_uuid(), $this->active_secret_for( $bot ), 902, '-100998', 78 )
		);

		$this->assertSame( 200, $response->get_status() );

		$fresh = $this->conversations->find( $conversation->id() );
		$this->assertSame( 'unavailable', $fresh->topic_lifecycle_state() );
	}
}
