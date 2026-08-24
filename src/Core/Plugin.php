<?php
/**
 * Composition root.
 *
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Core;

use UniversalTelegram\Administration\AI\AIDiagnosticsPanel;
use UniversalTelegram\Administration\AI\AISettingsPage;
use UniversalTelegram\Administration\AI\ApprovedContentPage;
use UniversalTelegram\Administration\AI\ConversationDraftPanel;
use UniversalTelegram\Administration\Automations\EventCatalogPage;
use UniversalTelegram\Administration\Automations\EventHistoryPage;
use UniversalTelegram\Administration\Automations\RuleBuilderPage;
use UniversalTelegram\Administration\Automations\RuleBuilderRequestHandler;
use UniversalTelegram\Administration\Automations\RuleSimulatorPage;
use UniversalTelegram\Administration\Conversations\ConversationActionHandler;
use UniversalTelegram\Administration\Conversations\ConversationDetailPage;
use UniversalTelegram\Administration\Conversations\ConversationInboxPage;
use UniversalTelegram\Administration\Conversations\OperatorIdentityPage;
use UniversalTelegram\Administration\Conversations\OperatorIdentityRequestHandler;
use UniversalTelegram\Administration\Diagnostics\DiagnosticsPage;
use UniversalTelegram\Administration\Diagnostics\DiagnosticsReport;
use UniversalTelegram\Administration\Diagnostics\SelfTest;
use UniversalTelegram\Administration\Hub\HubPage;
use UniversalTelegram\Administration\Hub\LegacyUrlRedirector;
use UniversalTelegram\Administration\Hub\OverviewPage;
use UniversalTelegram\Administration\Hub\SettingsPage;
use UniversalTelegram\Administration\Hub\Tab;
use UniversalTelegram\Administration\Hub\TabRegistry;
use UniversalTelegram\Administration\PluginActionLinks;
use UniversalTelegram\Administration\Telegram\BotManagementController;
use UniversalTelegram\Administration\Telegram\BotManagementPage;
use UniversalTelegram\Administration\Telegram\BotSetupWizardRenderer;
use UniversalTelegram\Administration\Telegram\BotSetupWizardState;
use UniversalTelegram\Administration\Telegram\TelegramFormFields;
use UniversalTelegram\Administration\Visitor\VisitorTrackingPage;
use UniversalTelegram\AI\Config\AIProviderRepository;
use UniversalTelegram\AI\Content\ApprovedContentRepository;
use UniversalTelegram\AI\Draft\AIDraftGenerationHandler;
use UniversalTelegram\AI\Draft\AiDraftLeaseSweep;
use UniversalTelegram\AI\Draft\AiDraftRepository;
use UniversalTelegram\AI\Draft\DraftRequestHandler;
use UniversalTelegram\AI\Draft\PromptBuilder;
use UniversalTelegram\AI\Provider\AiFailureClassifier;
use UniversalTelegram\Audit\AuditLogger;
use UniversalTelegram\Audit\AuditLogRepository;
use UniversalTelegram\Automations\Digest\DigestEligibility;
use UniversalTelegram\Automations\DispatchLogRepository;
use UniversalTelegram\Automations\NotificationDispatcher;
use UniversalTelegram\Automations\NotificationRuleRepository;
use UniversalTelegram\Automations\RuleEvaluator;
use UniversalTelegram\Automations\RuleSimulator;
use UniversalTelegram\Automations\TemplateRenderer;
use UniversalTelegram\ChatWidget\AccountUrlResolver;
use UniversalTelegram\ChatWidget\ChatWidgetAssets;
use UniversalTelegram\ChatWidget\ChatWidgetAvailability;
use UniversalTelegram\Conversations\ChatProfileResolver;
use UniversalTelegram\Conversations\ConversationNoteRepository;
use UniversalTelegram\Conversations\ConversationOutboundDispatcher;
use UniversalTelegram\Conversations\ConversationOutboundHandler;
use UniversalTelegram\Conversations\ConversationPurgeService;
use UniversalTelegram\Conversations\ConversationRepository;
use UniversalTelegram\Conversations\ImmediateDeliveryAttempt;
use UniversalTelegram\Conversations\MessageRepository;
use UniversalTelegram\Conversations\OperatorAvailabilityRepository;
use UniversalTelegram\Conversations\OperatorIdentityRepository;
use UniversalTelegram\Conversations\PromptDeliveryFallback;
use UniversalTelegram\Conversations\Rest\ConversationsController;
use UniversalTelegram\Conversations\RetentionCleanupHandler as ConversationRetentionCleanupHandler;
use UniversalTelegram\Conversations\TopicCreationDispatcher;
use UniversalTelegram\Conversations\TopicCreationHandler;
use UniversalTelegram\Conversations\VisitorTokenGenerator;
use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;
use UniversalTelegram\Core\Configuration\Settings;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Events\EventDispatcher;
use UniversalTelegram\Events\EventEmitter;
use UniversalTelegram\Events\EventHistoryRepository;
use UniversalTelegram\Events\Emitters\ContentEmitter;
use UniversalTelegram\Events\Emitters\FatalErrorMarkerWriter;
use UniversalTelegram\Events\Emitters\FatalErrorPromotionJob;
use UniversalTelegram\Events\Emitters\LoginEmitter;
use UniversalTelegram\Events\Emitters\MailFailureEmitter;
use UniversalTelegram\Events\Emitters\PluginLifecycleEmitter;
use UniversalTelegram\Events\Emitters\RestRequestFailureEmitter;
use UniversalTelegram\Events\Emitters\ScheduledTaskFailureEmitter;
use UniversalTelegram\Events\Emitters\UpdateEmitter;
use UniversalTelegram\Events\Emitters\UserLifecycleEmitter;
use UniversalTelegram\Events\Visitor\BotFilter;
use UniversalTelegram\Events\Visitor\IngestController;
use UniversalTelegram\Events\Visitor\IngestRequestValidator;
use UniversalTelegram\Events\Visitor\PageContext;
use UniversalTelegram\Events\Visitor\Sampler;
use UniversalTelegram\Events\Visitor\TrackerAssets;
use UniversalTelegram\Events\Visitor\VisitorEventCatalog;
use UniversalTelegram\Integrations\WooCommerce\Events\CartEventEmitter;
use UniversalTelegram\Integrations\WooCommerce\Events\CheckoutEventEmitter;
use UniversalTelegram\Integrations\WooCommerce\Events\CouponEventEmitter;
use UniversalTelegram\Integrations\WooCommerce\Events\OrderEventEmitter;
use UniversalTelegram\Integrations\WooCommerce\Events\StockEventEmitter;
use UniversalTelegram\Integrations\WooCommerce\Visitor\VisitorCommerceEventCatalog;
use UniversalTelegram\Events\Registry;
use UniversalTelegram\Events\RetentionCleanup;
use UniversalTelegram\Integrations\WooCommerce\WooCommerceCommandQueryService;
use UniversalTelegram\Integrations\WooCommerce\WooCommerceSupport;
use UniversalTelegram\Persistence\MigrationFailedException;
use UniversalTelegram\Persistence\MigrationLock;
use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Privacy\Classification;
use UniversalTelegram\Privacy\Redactor;
use UniversalTelegram\Queue\Dispatcher;
use UniversalTelegram\Queue\ExpeditedDispatchTrigger;
use UniversalTelegram\Queue\HandlerRegistry;
use UniversalTelegram\Queue\QueueHealth;
use UniversalTelegram\Queue\RetryPolicy;
use UniversalTelegram\Queue\WorkerRunner;
use UniversalTelegram\Telegram\Client\TelegramApiClient;
use UniversalTelegram\Telegram\Commands\BotCommandDispatcher;
use UniversalTelegram\Telegram\Commands\ConfirmationStore;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;
use UniversalTelegram\Telegram\Configuration\DestinationRepository;
use UniversalTelegram\Telegram\Inbound\UpdateRepository;
use UniversalTelegram\Telegram\Inbound\WebhookController;
use UniversalTelegram\Telegram\Inbound\WebhookSecretVerifier;
use UniversalTelegram\Telegram\Client\TelegramFailureClassifier;
use UniversalTelegram\Telegram\Outbound\MessageDispatcher;
use UniversalTelegram\Telegram\Outbound\OutboundMessageRepository;
use UniversalTelegram\Telegram\Outbound\RetentionCleanupHandler;
use UniversalTelegram\Telegram\Outbound\SendMessageHandler;
use UniversalTelegram\Telegram\Configuration\WebhookRegistrationCoordinator;
use UniversalTelegram\Telegram\Reliability\CircuitBreaker;
use UniversalTelegram\Telegram\Reliability\QueueHealthAlert;
use UniversalTelegram\Telegram\Reliability\RateLimiter;

/**
 * Singleton composition root. Constructs and wires every M00 service by
 * hand inside init(); no dependency-injection container. Every service is
 * always constructed and always wired, regardless of schema availability;
 * individual database-touching operations check SchemaHealth at their own
 * point of use instead. See docs/adr/0007.
 */
final class Plugin {

	/**
	 * Shared instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Whether init() has already run.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * The current request's schema-availability state, set by init().
	 *
	 * @var SchemaHealth|null
	 */
	private ?SchemaHealth $schema_health = null;

	/**
	 * The audit log writer, constructed by init().
	 *
	 * @var AuditLogger|null
	 */
	private ?AuditLogger $audit_logger = null;

	/**
	 * The audit log reader, constructed by init().
	 *
	 * @var AuditLogRepository|null
	 */
	private ?AuditLogRepository $audit_log_repository = null;

	/**
	 * The WooCommerce-presence detector, constructed by init().
	 *
	 * @var WooCommerceSupport|null
	 */
	private ?WooCommerceSupport $woocommerce_support = null;

	/**
	 * The credential vault, constructed by init().
	 *
	 * @var CredentialVault|null
	 */
	private ?CredentialVault $credential_vault = null;

	/**
	 * The capability registrar, constructed by init().
	 *
	 * @var CapabilityRegistrar|null
	 */
	private ?CapabilityRegistrar $capability_registrar = null;

	/**
	 * The internal job-type-to-handler map, constructed by init().
	 *
	 * @var HandlerRegistry|null
	 */
	private ?HandlerRegistry $handler_registry = null;

	/**
	 * The job dispatcher, constructed by init().
	 *
	 * @var Dispatcher|null
	 */
	private ?Dispatcher $dispatcher = null;

	/**
	 * The queue's pending/failed action counts, constructed by init().
	 *
	 * @var QueueHealth|null
	 */
	private ?QueueHealth $queue_health = null;

	/**
	 * The worker runner, constructed by init() and registered against
	 * WorkerRunner::HOOK unconditionally.
	 *
	 * @var WorkerRunner|null
	 */
	private ?WorkerRunner $worker_runner = null;

	/**
	 * The diagnostics admin page, constructed by init().
	 *
	 * @var DiagnosticsPage|null
	 */
	private ?DiagnosticsPage $diagnostics_page = null;

	/**
	 * The administration hub's tab registry, constructed by init().
	 *
	 * @var TabRegistry|null
	 */
	private ?TabRegistry $hub_tab_registry = null;

	/**
	 * The administration hub shell page, constructed by init().
	 *
	 * @var HubPage|null
	 */
	private ?HubPage $hub_page = null;

	/**
	 * The Settings tab page, constructed by init().
	 *
	 * @var SettingsPage|null
	 */
	private ?SettingsPage $settings_page = null;

	/**
	 * AI provider configuration persistence (M09), constructed by init().
	 *
	 * @var AIProviderRepository|null
	 */
	private ?AIProviderRepository $ai_provider_repository = null;

	/**
	 * AI draft persistence (M09), constructed by init().
	 *
	 * @var AiDraftRepository|null
	 */
	private ?AiDraftRepository $ai_draft_repository = null;

	/**
	 * The AI tab page (M09), constructed by init().
	 *
	 * @var AISettingsPage|null
	 */
	private ?AISettingsPage $ai_settings_page = null;

	/**
	 * Approved AI source-content persistence (M09), constructed by init().
	 *
	 * @var ApprovedContentRepository|null
	 */
	private ?ApprovedContentRepository $approved_content_repository = null;

	/**
	 * The AI Content tab page (M09), constructed by init().
	 *
	 * @var ApprovedContentPage|null
	 */
	private ?ApprovedContentPage $approved_content_page = null;

	/**
	 * The bounded diagnostic self-test, constructed by init().
	 *
	 * @var SelfTest|null
	 */
	private ?SelfTest $self_test = null;

	/**
	 * The bot profile repository, constructed by init().
	 *
	 * @var BotProfileRepository|null
	 */
	private ?BotProfileRepository $bot_profile_repository = null;

	/**
	 * The destination repository, constructed by init().
	 *
	 * @var DestinationRepository|null
	 */
	private ?DestinationRepository $destination_repository = null;

	/**
	 * The Telegram Bot API client, constructed by init().
	 *
	 * @var TelegramApiClient|null
	 */
	private ?TelegramApiClient $telegram_api_client = null;

	/**
	 * The outbound message repository, constructed by init().
	 *
	 * @var OutboundMessageRepository|null
	 */
	private ?OutboundMessageRepository $outbound_message_repository = null;

	/**
	 * The outbound message dispatcher, constructed by init().
	 *
	 * @var MessageDispatcher|null
	 */
	private ?MessageDispatcher $message_dispatcher = null;

	/**
	 * The inbound update repository, constructed by init().
	 *
	 * @var UpdateRepository|null
	 */
	private ?UpdateRepository $update_repository = null;

	/**
	 * The webhook secret verifier, constructed by init().
	 *
	 * @var WebhookSecretVerifier|null
	 */
	private ?WebhookSecretVerifier $webhook_secret_verifier = null;

	/**
	 * The inbound webhook REST controller, constructed by init().
	 *
	 * @var WebhookController|null
	 */
	private ?WebhookController $webhook_controller = null;

	/**
	 * Administrative-bot command authorization/context/dispatch (M08,
	 * docs/adr/0027), constructed by init().
	 *
	 * @var BotCommandDispatcher|null
	 */
	private ?BotCommandDispatcher $bot_command_dispatcher = null;

	/**
	 * The per-bot/per-destination rate limiter, constructed by init().
	 *
	 * @var RateLimiter|null
	 */
	private ?RateLimiter $rate_limiter = null;

	/**
	 * Conversation persistence, constructed by init().
	 *
	 * @var ConversationRepository|null
	 */
	private ?ConversationRepository $conversation_repository = null;

	/**
	 * Conversation message persistence, constructed by init().
	 *
	 * @var MessageRepository|null
	 */
	private ?MessageRepository $message_repository = null;

	/**
	 * Operator identity mapping persistence, constructed by init() (M07, docs/adr/0026).
	 *
	 * @var OperatorIdentityRepository|null
	 */
	private ?OperatorIdentityRepository $operator_identity_repository = null;

	/**
	 * Operator availability persistence, constructed by init() (M07, docs/adr/0026).
	 *
	 * @var OperatorAvailabilityRepository|null
	 */
	private ?OperatorAvailabilityRepository $operator_availability_repository = null;

	/**
	 * Conversation internal note persistence, constructed by init() (M07, docs/adr/0026).
	 *
	 * @var ConversationNoteRepository|null
	 */
	private ?ConversationNoteRepository $conversation_note_repository = null;

	/**
	 * The operator identity mapping admin page, constructed by init() (M07, docs/adr/0026).
	 *
	 * @var OperatorIdentityPage|null
	 */
	private ?OperatorIdentityPage $operator_identity_page = null;

	/**
	 * The operator identity mapping request handler, constructed by init() (M07, docs/adr/0026).
	 *
	 * @var OperatorIdentityRequestHandler|null
	 */
	private ?OperatorIdentityRequestHandler $operator_identity_request_handler = null;

	/**
	 * The operator conversation-workflow action handler, constructed by init() (M07, docs/adr/0026).
	 *
	 * @var ConversationActionHandler|null
	 */
	private ?ConversationActionHandler $conversation_action_handler = null;

	/**
	 * The operator conversation detail page, constructed by init() (M07, docs/adr/0026).
	 *
	 * @var ConversationDetailPage|null
	 */
	private ?ConversationDetailPage $conversation_detail_page = null;

	/**
	 * The operator conversation inbox page, constructed by init() (M07, docs/adr/0026).
	 *
	 * @var ConversationInboxPage|null
	 */
	private ?ConversationInboxPage $conversation_inbox_page = null;

	/**
	 * The public visitor conversation REST controller, constructed by init().
	 *
	 * @var ConversationsController|null
	 */
	private ?ConversationsController $conversations_controller = null;

	/**
	 * Idempotent Telegram forum-topic creation dispatch, constructed by init().
	 *
	 * @var TopicCreationDispatcher|null
	 */
	private ?TopicCreationDispatcher $topic_creation_dispatcher = null;

	/**
	 * The per-bot/per-destination circuit breaker, constructed by init().
	 *
	 * @var CircuitBreaker|null
	 */
	private ?CircuitBreaker $circuit_breaker = null;

	/**
	 * The queue-health alert computation, constructed by init().
	 *
	 * @var QueueHealthAlert|null
	 */
	private ?QueueHealthAlert $queue_health_alert = null;

	/**
	 * The retention cleanup handler, constructed by init().
	 *
	 * @var RetentionCleanupHandler|null
	 */
	private ?RetentionCleanupHandler $retention_cleanup_handler = null;

	/**
	 * The conversation retention cleanup handler, constructed by init().
	 *
	 * @var ConversationRetentionCleanupHandler|null
	 */
	private ?ConversationRetentionCleanupHandler $conversation_retention_cleanup_handler = null;

	/**
	 * The shared conversation purge service, constructed by init(). Used by
	 * both the scheduled retention handler above and M07's manual
	 * "delete archived conversation" admin action (docs/adr/0026).
	 *
	 * @var ConversationPurgeService|null
	 */
	private ?ConversationPurgeService $conversation_purge_service = null;

	/**
	 * The webhook registration/rotation coordinator, constructed by init().
	 *
	 * @var WebhookRegistrationCoordinator|null
	 */
	private ?WebhookRegistrationCoordinator $webhook_registration_coordinator = null;

	/**
	 * The bot/destination management admin page, constructed by init().
	 *
	 * @var BotManagementPage|null
	 */
	private ?BotManagementPage $bot_management_page = null;

	/**
	 * The bot/destination management request handler, constructed by init().
	 *
	 * @var BotManagementController|null
	 */
	private ?BotManagementController $bot_management_controller = null;

	/**
	 * The current request's event registry, constructed by init().
	 *
	 * @var Registry|null
	 */
	private ?Registry $event_registry = null;

	/**
	 * The internal event ingestion orchestrator, constructed by init().
	 *
	 * @var EventDispatcher|null
	 */
	private ?EventDispatcher $event_dispatcher = null;

	/**
	 * The safety-wrapped event emission façade, constructed by init().
	 *
	 * @var EventEmitter|null
	 */
	private ?EventEmitter $event_emitter = null;

	/**
	 * The notification rule repository, constructed by init().
	 *
	 * @var NotificationRuleRepository|null
	 */
	private ?NotificationRuleRepository $notification_rule_repository = null;

	/**
	 * The M11A visitor digest shared eligibility gate, constructed by init().
	 *
	 * @var DigestEligibility|null
	 */
	private ?DigestEligibility $digest_eligibility = null;

	/**
	 * The idempotent dispatch-log repository, constructed by init().
	 *
	 * @var DispatchLogRepository|null
	 */
	private ?DispatchLogRepository $dispatch_log_repository = null;

	/**
	 * The event history repository, constructed by init().
	 *
	 * @var EventHistoryRepository|null
	 */
	private ?EventHistoryRepository $event_history_repository = null;

	/**
	 * The event catalog admin page, constructed by init().
	 *
	 * @var EventCatalogPage|null
	 */
	private ?EventCatalogPage $event_catalog_page = null;

	/**
	 * The rule builder admin page, constructed by init().
	 *
	 * @var RuleBuilderPage|null
	 */
	private ?RuleBuilderPage $rule_builder_page = null;

	/**
	 * The rule builder request handler, constructed by init().
	 *
	 * @var RuleBuilderRequestHandler|null
	 */
	private ?RuleBuilderRequestHandler $rule_builder_request_handler = null;

	/**
	 * The rule simulator admin page, constructed by init().
	 *
	 * @var RuleSimulatorPage|null
	 */
	private ?RuleSimulatorPage $rule_simulator_page = null;

	/**
	 * The event history browser admin page, constructed by init().
	 *
	 * @var EventHistoryPage|null
	 */
	private ?EventHistoryPage $event_history_page = null;

	/**
	 * The visitor tracking settings admin page, constructed by init().
	 *
	 * @var VisitorTrackingPage|null
	 */
	private ?VisitorTrackingPage $visitor_tracking_page = null;

	/**
	 * Private constructor; use instance().
	 */
	private function __construct() {}

	/**
	 * Returns the single shared instance.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructs and wires every service. Idempotent: a second call is a
	 * no-op and never re-registers a hook.
	 */
	public function init(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		$settings        = new Settings();
		$settings_values = $settings->get();
		add_action( 'admin_init', array( $settings, 'register' ) );

		$this->schema_health = new SchemaHealth();
		$migrator            = new Migrator( new MigrationLock() );

		try {
			$migrator->maybe_migrate();
		} catch ( MigrationFailedException $exception ) {
			$this->schema_health->mark_unavailable( $exception->failure_code() );
		}

		$this->audit_logger         = new AuditLogger( $this->schema_health, new Redactor() );
		$this->audit_log_repository = new AuditLogRepository( $this->schema_health );

		$this->woocommerce_support = new WooCommerceSupport();

		$this->credential_vault = new CredentialVault();

		// Constructed early (M09, docs/adr/0028): ConversationsController
		// needs it at conversation-creation time to resolve the visitor
		// acknowledgement gate, well before the Hub's own AI tab is
		// registered later in this method.
		$this->ai_provider_repository = new AIProviderRepository( $this->schema_health, $this->credential_vault );
		$this->ai_draft_repository    = new AiDraftRepository( $this->schema_health, $this->credential_vault );

		$this->capability_registrar = new CapabilityRegistrar();

		$this->handler_registry = new HandlerRegistry();
		$this->dispatcher       = new Dispatcher( $this->schema_health );
		$this->queue_health     = new QueueHealth();

		// Always registered, in every request, regardless of schema
		// availability — an unregistered hook would let Action Scheduler
		// silently mark an already-scheduled job complete without it ever
		// running. See docs/adr/0006.
		$this->worker_runner = new WorkerRunner(
			$this->schema_health,
			$this->handler_registry,
			new RetryPolicy(),
			$this->audit_logger
		);
		add_action( WorkerRunner::HOOK, array( $this->worker_runner, 'run' ) );

		$this->self_test = new SelfTest(
			$this->schema_health,
			$this->dispatcher,
			$this->credential_vault,
			$this->audit_logger
		);
		$this->handler_registry->register( SelfTest::JOB_TYPE, array( $this->self_test, 'handle_job' ) );
		add_action( 'admin_post_' . $this->self_test->admin_post_action(), array( $this->self_test, 'handle_request' ) );

		$this->bot_profile_repository      = new BotProfileRepository( $this->schema_health, $this->credential_vault );
		$this->destination_repository      = new DestinationRepository( $this->schema_health );
		$this->telegram_api_client         = new TelegramApiClient();
		$this->outbound_message_repository = new OutboundMessageRepository( $this->schema_health, $this->credential_vault );
		$this->message_dispatcher          = new MessageDispatcher( $this->outbound_message_repository, $this->dispatcher );
		$this->rate_limiter                = new RateLimiter( $this->schema_health );
		$this->circuit_breaker             = new CircuitBreaker( $this->schema_health, new RetryPolicy() );
		$this->queue_health_alert          = new QueueHealthAlert( $this->outbound_message_repository, $this->circuit_breaker, $this->bot_profile_repository );

		$send_message_handler = new SendMessageHandler(
			$this->outbound_message_repository,
			$this->bot_profile_repository,
			$this->destination_repository,
			$this->telegram_api_client,
			new TelegramFailureClassifier(),
			$this->rate_limiter,
			$this->circuit_breaker,
			$this->audit_logger,
			new RetryPolicy(),
			(int) $settings_values['telegram_rate_limit_fallback_wait_seconds'],
			(int) $settings_values['telegram_max_pending_seconds']
		);
		$this->handler_registry->register( MessageDispatcher::JOB_TYPE, array( $send_message_handler, 'handle_job' ) );

		$this->conversation_repository = new ConversationRepository( $this->schema_health, $this->credential_vault, new VisitorTokenGenerator() );
		$this->message_repository      = new MessageRepository( $this->schema_health, $this->credential_vault );

		$this->operator_identity_repository     = new OperatorIdentityRepository( $this->schema_health );
		$this->operator_availability_repository = new OperatorAvailabilityRepository( $this->schema_health );
		$this->conversation_note_repository     = new ConversationNoteRepository( $this->schema_health, $this->credential_vault );

		$this->update_repository       = new UpdateRepository( $this->schema_health );
		$this->webhook_secret_verifier = new WebhookSecretVerifier( $this->bot_profile_repository, $this->audit_logger );

		// Constructed here (ahead of the Events/Automations block further
		// below, which constructs the remaining two M02 repositories) so
		// BotCommandDispatcher's read-only /status, /errors, and /visitors
		// commands (M08) can use event_history_repository's existing
		// aggregate-count methods without duplicating them.
		$this->event_registry           = new Registry();
		$this->event_history_repository = new EventHistoryRepository( $this->schema_health, $this->event_registry, new Redactor() );

		$this->bot_command_dispatcher = new BotCommandDispatcher(
			$this->operator_identity_repository,
			$this->conversation_repository,
			new ChatProfileResolver( $this->bot_profile_repository, $this->destination_repository ),
			$this->operator_availability_repository,
			$this->queue_health,
			$this->event_history_repository,
			$this->woocommerce_support,
			new WooCommerceCommandQueryService(),
			new ConfirmationStore(),
			$this->message_dispatcher,
			$this->audit_logger
		);

		$this->webhook_controller = new WebhookController(
			$this->schema_health,
			$this->bot_profile_repository,
			$this->webhook_secret_verifier,
			$this->update_repository,
			$this->conversation_repository,
			$this->message_repository,
			new ChatProfileResolver( $this->bot_profile_repository, $this->destination_repository ),
			$this->operator_identity_repository,
			$this->audit_logger,
			$this->bot_command_dispatcher,
			(int) $settings_values['telegram_webhook_max_body_bytes']
		);
		add_action( 'rest_api_init', array( $this->webhook_controller, 'register_routes' ) );

		$this->topic_creation_dispatcher = new TopicCreationDispatcher( $this->conversation_repository, $this->dispatcher );
		$topic_creation_handler          = new TopicCreationHandler(
			$this->conversation_repository,
			$this->bot_profile_repository,
			new ChatProfileResolver( $this->bot_profile_repository, $this->destination_repository ),
			$this->destination_repository,
			$this->telegram_api_client,
			new RetryPolicy()
		);
		$this->handler_registry->register( TopicCreationHandler::JOB_TYPE, array( $topic_creation_handler, 'handle_job' ) );

		$conversation_outbound_dispatcher = new ConversationOutboundDispatcher( $this->dispatcher );
		$conversation_outbound_handler    = new ConversationOutboundHandler(
			$this->message_repository,
			$this->conversation_repository,
			$this->outbound_message_repository,
			$this->dispatcher
		);
		$this->handler_registry->register( ConversationOutboundHandler::JOB_TYPE, array( $conversation_outbound_handler, 'handle_job' ) );

		$expedited_dispatch_trigger = new ExpeditedDispatchTrigger( $this->audit_logger );

		// Primary interactive-latency mechanism (M06.2 corrective plan v2
		// §3.2–§3.3, ADR-0023 amendment): a bounded, claim-protected
		// synchronous attempt, shared unmodified with the durable queue
		// handlers above, plus a host-independent bounded second-layer
		// fallback. ExpeditedDispatchTrigger is retained but demoted to
		// only the fallback's own final branch.
		$immediate_delivery_attempt = new ImmediateDeliveryAttempt(
			$this->conversation_repository,
			$this->bot_profile_repository,
			$this->destination_repository,
			$this->outbound_message_repository,
			$conversation_outbound_handler,
			$this->message_repository,
			new TelegramFailureClassifier(),
			$this->rate_limiter,
			$this->circuit_breaker,
			$this->audit_logger,
			new RetryPolicy()
		);
		$prompt_delivery_fallback   = new PromptDeliveryFallback( $immediate_delivery_attempt, $expedited_dispatch_trigger );

		$this->conversations_controller = new ConversationsController(
			$this->schema_health,
			$this->conversation_repository,
			$this->message_repository,
			new VisitorTokenGenerator(),
			new ChatProfileResolver( $this->bot_profile_repository, $this->destination_repository ),
			$this->rate_limiter,
			$this->topic_creation_dispatcher,
			$conversation_outbound_dispatcher,
			$immediate_delivery_attempt,
			$prompt_delivery_fallback,
			$settings,
			$this->ai_provider_repository
		);
		add_action( 'rest_api_init', array( $this->conversations_controller, 'register_routes' ) );

		$this->retention_cleanup_handler = new RetentionCleanupHandler(
			$this->outbound_message_repository,
			(int) $settings_values['telegram_message_retention_days'],
			(int) $settings_values['telegram_delivery_log_retention_days']
		);
		add_action( RetentionCleanupHandler::HOOK, array( $this->retention_cleanup_handler, 'run' ) );

		// Action Scheduler's own data store is only guaranteed ready once
		// WordPress' own `init` action has fired; scheduling here,
		// deferred, avoids calling it before that point (docs/adr/0006).
		add_action(
			'init',
			static function () {
				if ( ! as_has_scheduled_action( RetentionCleanupHandler::HOOK, array(), WorkerRunner::GROUP ) ) {
					as_schedule_recurring_action( time() + DAY_IN_SECONDS, DAY_IN_SECONDS, RetentionCleanupHandler::HOOK, array(), WorkerRunner::GROUP );
				}
			}
		);

		$this->conversation_purge_service = new ConversationPurgeService(
			$this->conversation_repository,
			$this->message_repository,
			$this->destination_repository
		);

		$this->conversation_retention_cleanup_handler = new ConversationRetentionCleanupHandler(
			$this->conversation_repository,
			$this->message_repository,
			$this->conversation_purge_service
		);
		add_action( ConversationRetentionCleanupHandler::HOOK, array( $this->conversation_retention_cleanup_handler, 'run' ) );

		// Fixed defaults, no settings UI in M05 (M05 plan §9); scheduled the
		// same deferred way as the Telegram outbound retention action above.
		add_action(
			'init',
			static function () {
				if ( ! as_has_scheduled_action( ConversationRetentionCleanupHandler::HOOK, array(), WorkerRunner::GROUP ) ) {
					as_schedule_recurring_action( time() + DAY_IN_SECONDS, DAY_IN_SECONDS, ConversationRetentionCleanupHandler::HOOK, array(), WorkerRunner::GROUP );
				}
			}
		);

		// Account deletion (M06.3.1, ADR-0025): revokes the bearer secret and
		// clears owner_user_id for every conversation the deleted account
		// owned, so the numeric id is never retained. Message rows and the
		// existing retention-age sweeps are untouched. Logout itself needs
		// no handler — every route re-checks the live cookie session per
		// request.
		add_action(
			'deleted_user',
			function ( int $user_id ): void {
				$this->conversation_repository->release_owner_conversations( $user_id );
			}
		);

		// Operator-side account-deletion cleanup (M07, ADR-0026 decision 12):
		// additive to, never a replacement of, the visitor-owner cleanup
		// above. Runs only if the deleted user was ever mapped as an
		// operator. Order matters: the mapped Telegram id is resolved
		// before the mapping row that holds it is deleted, since it is the
		// lookup key for clearing message attribution.
		add_action(
			'deleted_user',
			function ( int $user_id ): void {
				$identity = $this->operator_identity_repository->find_by_wp_user_id( $user_id );

				if ( null === $identity ) {
					return;
				}

				$this->message_repository->clear_sender_attribution( $identity->telegram_user_id() );
				$this->conversation_note_repository->anonymize_author( $user_id );
				$this->conversation_repository->clear_assignment_for_operator( $user_id );
				$this->operator_availability_repository->delete_for_operator( $user_id );
				$this->operator_identity_repository->delete_for_wp_user( $user_id );
				// M09, docs/adr/0028 §4: AI draft content is untouched — only
				// the requester/reviewer identity is anonymized.
				$this->ai_draft_repository->anonymize_operator( $user_id );

				$this->audit_logger->record(
					'conversation.operator_identity.account_deleted_cleanup',
					'system',
					null,
					array(),
					array(),
					Classification::INTERNAL
				);
			}
		);

		// rest_url() is not called here: WordPress' rewrite state is not
		// yet initialized during plugins_loaded (the hook init() runs on),
		// so the URL is computed lazily, only when an operation is
		// actually attempted, well after WordPress' own 'init' hook.
		$this->webhook_registration_coordinator = new WebhookRegistrationCoordinator(
			$this->bot_profile_repository,
			$this->telegram_api_client,
			$this->audit_logger,
			static function (): string {
				return rest_url( 'universal-telegram/v1/webhook/' );
			}
		);

		$this->bot_management_controller = new BotManagementController(
			$this->bot_profile_repository,
			$this->destination_repository,
			$this->outbound_message_repository,
			$this->telegram_api_client,
			$this->webhook_registration_coordinator,
			$this->dispatcher,
			new TelegramApiClient( 8 ),
			new TelegramFailureClassifier(),
			$this->audit_logger
		);
		add_action( 'admin_post_' . BotManagementController::ADMIN_POST_ACTION, array( $this->bot_management_controller, 'handle_request' ) );

		$telegram_form_fields = new TelegramFormFields();

		$bot_setup_wizard_state = new BotSetupWizardState(
			new ChatProfileResolver( $this->bot_profile_repository, $this->destination_repository ),
			new ChatWidgetAvailability( $settings, new ChatProfileResolver( $this->bot_profile_repository, $this->destination_repository ) ),
			$this->destination_repository
		);

		$bot_setup_wizard_renderer = new BotSetupWizardRenderer(
			$bot_setup_wizard_state,
			$telegram_form_fields,
			$this->bot_profile_repository
		);

		$this->bot_management_page = new BotManagementPage(
			$this->bot_profile_repository,
			$this->destination_repository,
			$this->update_repository,
			$this->outbound_message_repository,
			$telegram_form_fields,
			$bot_setup_wizard_state,
			$bot_setup_wizard_renderer,
			$this->conversation_repository
		);

		// Events/Automations (M02) repositories: event_registry and
		// event_history_repository are constructed earlier (see above,
		// ahead of BotCommandDispatcher, which needs the latter for
		// /status /errors /visitors); the remaining two are constructed
		// here, ahead of DiagnosticsReport below (which reads their
		// aggregate counts), always unconditionally regardless of schema
		// availability — individual repositories check SchemaHealth at
		// their own point of use (docs/adr/0007).
		$this->notification_rule_repository = new NotificationRuleRepository( $this->schema_health, $this->event_registry );
		$this->dispatch_log_repository      = new DispatchLogRepository( $this->schema_health );

		$report                 = new DiagnosticsReport(
			$this->queue_health,
			$this->audit_log_repository,
			$this->woocommerce_support,
			$this->schema_health,
			$this->bot_profile_repository,
			$this->destination_repository,
			$this->queue_health_alert,
			$this->event_history_repository,
			$this->notification_rule_repository,
			$this->dispatch_log_repository,
			$settings,
			(int) $settings_values['telegram_stale_pending_alert_seconds'],
			(int) $settings_values['telegram_webhook_rotation_max_pending_hours']
		);
		$this->diagnostics_page = new DiagnosticsPage(
			$report,
			$this->schema_health,
			$this->self_test,
			$this->queue_health_alert,
			(int) $settings_values['telegram_stale_pending_alert_seconds'],
			(int) $settings_values['telegram_webhook_rotation_max_pending_hours'],
			new AIDiagnosticsPanel( $this->ai_draft_repository, $this->circuit_breaker )
		);
		add_action( 'admin_notices', array( $this->diagnostics_page, 'render_admin_notice' ) );

		// Administration hub (M04.1, ADR-0020): a single top-level menu
		// entry with URL-driven tabs, superseding the per-screen
		// add_submenu_page()/add_menu_page() pattern every screen below
		// used through M04. Tabs are registered here and at each
		// migrated screen's own former wiring point, in the plan's
		// work-package order; register_menu() itself is wired once.
		$overview_page          = new OverviewPage();
		$this->hub_tab_registry = new TabRegistry();
		$this->hub_tab_registry->register(
			new Tab( OverviewPage::TAB_ID, __( 'Overview', 'universal-telegram' ), CapabilityRegistrar::MANAGE, array( $overview_page, 'render_tab_content' ) )
		);
		$this->hub_tab_registry->register(
			new Tab( 'bots', __( 'Bots', 'universal-telegram' ), CapabilityRegistrar::MANAGE, array( $this->bot_management_page, 'render_tab_content' ) )
		);
		// 'diagnostics' is registered last of all (see below), so display
		// order matches the plan's mapping (M04.1 plan §3): Overview,
		// Bots, Events, Rules, Simulator, Event History, Visitor
		// Tracking, Settings, Diagnostics.
		$this->hub_page = new HubPage( $this->hub_tab_registry );
		add_action( 'admin_menu', array( $this->hub_page, 'register_menu' ) );

		// Events/Automations (M02) continued: the repositories above are
		// already constructed; wire the remaining dispatch/evaluation/
		// emission services.
		$notification_dispatcher = new NotificationDispatcher(
			$this->dispatch_log_repository,
			$this->bot_profile_repository,
			$this->destination_repository,
			$this->event_registry,
			new TemplateRenderer(),
			$this->message_dispatcher
		);
		$rule_evaluator          = new RuleEvaluator( $this->notification_rule_repository, $this->event_registry, $this->dispatch_log_repository, $notification_dispatcher );
		$this->event_dispatcher  = new EventDispatcher( $this->event_history_repository, $rule_evaluator );
		$this->event_emitter     = new EventEmitter( $this->event_registry, $this->event_dispatcher, $this->audit_logger );

		// Core WordPress event emitters (M02 plan §8): constructed and
		// wired unconditionally, at bootstrap. Each registers its own
		// event type(s) at priority 10 on universal_telegram_register_event_types,
		// and its own WordPress hook callback(s) directly.
		$login_emitter                  = new LoginEmitter();
		$user_lifecycle_emitter         = new UserLifecycleEmitter();
		$content_emitter                = new ContentEmitter();
		$plugin_lifecycle_emitter       = new PluginLifecycleEmitter();
		$update_emitter                 = new UpdateEmitter();
		$scheduled_task_failure_emitter = new ScheduledTaskFailureEmitter();
		$rest_request_failure_emitter   = new RestRequestFailureEmitter();
		$mail_failure_emitter           = new MailFailureEmitter();
		$fatal_error_promotion_job      = new FatalErrorPromotionJob( $this->schema_health );

		add_action( 'universal_telegram_register_event_types', array( $login_emitter, 'register_event_types' ), 10 );
		add_action( 'universal_telegram_register_event_types', array( $user_lifecycle_emitter, 'register_event_types' ), 10 );
		add_action( 'universal_telegram_register_event_types', array( $content_emitter, 'register_event_types' ), 10 );
		add_action( 'universal_telegram_register_event_types', array( $plugin_lifecycle_emitter, 'register_event_types' ), 10 );
		add_action( 'universal_telegram_register_event_types', array( $update_emitter, 'register_event_types' ), 10 );
		add_action( 'universal_telegram_register_event_types', array( $scheduled_task_failure_emitter, 'register_event_types' ), 10 );
		add_action( 'universal_telegram_register_event_types', array( $rest_request_failure_emitter, 'register_event_types' ), 10 );
		add_action( 'universal_telegram_register_event_types', array( $mail_failure_emitter, 'register_event_types' ), 10 );
		add_action( 'universal_telegram_register_event_types', array( $fatal_error_promotion_job, 'register_event_types' ), 10 );

		// Visitor/browser event catalog (M04 plan §4.2, ADR-0019): the six
		// always-on visitor.* types, registered unconditionally at priority
		// 20, independent of WooCommerce presence.
		$visitor_event_catalog = new VisitorEventCatalog();
		add_action( 'universal_telegram_register_event_types', array( $visitor_event_catalog, 'register_event_types' ), 20 );

		// Public visitor event ingestion endpoint (M04 plan §4.4,
		// ADR-0019): unauthenticated at the WP-REST layer, reusing the
		// same generic RateLimiter instance already constructed above
		// under two new scope_type values.
		$ingest_controller = new IngestController(
			$this->schema_health,
			$this->event_registry,
			$settings,
			$this->rate_limiter,
			new IngestRequestValidator(),
			new BotFilter(),
			new Sampler(),
			$this->audit_logger
		);
		add_action( 'rest_api_init', array( $ingest_controller, 'register_routes' ) );

		$tracker_assets = new TrackerAssets( $settings, new PageContext(), $this->woocommerce_support );
		add_action( 'wp_enqueue_scripts', array( $tracker_assets, 'enqueue' ) );

		$chat_widget_assets = new ChatWidgetAssets(
			new ChatWidgetAvailability(
				$settings,
				new ChatProfileResolver( $this->bot_profile_repository, $this->destination_repository )
			),
			$settings,
			new AccountUrlResolver(),
			$this->ai_provider_repository
		);
		add_action( 'wp_enqueue_scripts', array( $chat_widget_assets, 'enqueue' ) );
		add_action( 'wp_footer', array( $chat_widget_assets, 'print_config' ), 5 );

		// WooCommerce event emitters (M03 plan §4, ADR-0018): constructed
		// and wired only when WooCommerceSupport::is_active() is true.
		// WooCommerce absent/inactive/incompatible -> this entire block is
		// skipped, no emitter objects are constructed, no woocommerce.*
		// type is ever registered, and no WooCommerce hook callback is
		// ever bound. Zero runtime surface when WooCommerce is not present.
		if ( $this->woocommerce_support->is_active() ) {
			$order_event_emitter    = new OrderEventEmitter();
			$stock_event_emitter    = new StockEventEmitter();
			$cart_event_emitter     = new CartEventEmitter();
			$coupon_event_emitter   = new CouponEventEmitter();
			$checkout_event_emitter = new CheckoutEventEmitter();

			add_action( 'universal_telegram_register_event_types', array( $order_event_emitter, 'register_event_types' ), 10 );
			add_action( 'universal_telegram_register_event_types', array( $stock_event_emitter, 'register_event_types' ), 10 );
			add_action( 'universal_telegram_register_event_types', array( $cart_event_emitter, 'register_event_types' ), 10 );
			add_action( 'universal_telegram_register_event_types', array( $coupon_event_emitter, 'register_event_types' ), 10 );
			add_action( 'universal_telegram_register_event_types', array( $checkout_event_emitter, 'register_event_types' ), 10 );

			$order_event_emitter->register_hooks();
			$stock_event_emitter->register_hooks();
			$cart_event_emitter->register_hooks();
			$coupon_event_emitter->register_hooks();
			$checkout_event_emitter->register_hooks();

			// Visitor/browser commerce-gated event types (M04 plan §4.6):
			// registered only here, alongside the rest of M03's WooCommerce
			// wiring — no hook binding of their own, since both types are
			// entirely driven by IngestController via the tracker client.
			$visitor_commerce_event_catalog = new VisitorCommerceEventCatalog();
			add_action( 'universal_telegram_register_event_types', array( $visitor_commerce_event_catalog, 'register_event_types' ), 20 );
		}

		// Fired once, at priority 20, after WooCommerce presence detection
		// (already established above) and before any admin-menu
		// registration — core WordPress event types (§8) register at
		// priority 10 on this same hook (M02 plan §5.3).
		add_action(
			'init',
			function () {
				/**
				 * Fires once, at init priority 20, so third-party code (and
				 * later milestones) can register their own event types
				 * against the shared Events\Registry instance (M02 plan
				 * §5.3).
				 *
				 * @since 0.2.0
				 *
				 * @param Registry $event_registry The current request's event registry.
				 */
				do_action( 'universal_telegram_register_event_types', $this->event_registry );
			},
			20
		);

		add_action( 'wp_login', array( $login_emitter, 'on_login' ), 10, 2 );
		add_action( 'wp_login_failed', array( $login_emitter, 'on_login_failed' ), 10, 2 );
		add_action( 'user_register', array( $user_lifecycle_emitter, 'on_user_registered' ), 10, 1 );
		add_action( 'set_user_role', array( $user_lifecycle_emitter, 'on_role_changed' ), 10, 3 );
		add_action( 'after_password_reset', array( $user_lifecycle_emitter, 'on_password_reset' ), 10, 2 );
		add_action( 'transition_post_status', array( $content_emitter, 'on_post_status_transition' ), 10, 3 );
		add_action( 'comment_post', array( $content_emitter, 'on_comment_submitted' ), 10, 2 );
		add_action( 'activated_plugin', array( $plugin_lifecycle_emitter, 'on_activated' ), 10, 2 );
		add_action( 'deactivated_plugin', array( $plugin_lifecycle_emitter, 'on_deactivated' ), 10, 2 );
		add_action( 'upgrader_process_complete', array( $update_emitter, 'on_update_completed' ), 10, 2 );
		add_action( 'action_scheduler_failed_action', array( $scheduled_task_failure_emitter, 'on_action_failed' ), 10, 2 );
		add_filter( 'rest_request_after_callbacks', array( $rest_request_failure_emitter, 'on_rest_request_after_callbacks' ), 10, 3 );
		add_action( 'wp_mail_failed', array( $mail_failure_emitter, 'on_mail_failed' ), 10, 1 );

		add_action( UpdateEmitter::CHECK_HOOK, array( $update_emitter, 'check_for_updates' ) );
		add_action(
			'init',
			static function () {
				if ( ! as_has_scheduled_action( UpdateEmitter::CHECK_HOOK, array(), WorkerRunner::GROUP ) ) {
					as_schedule_recurring_action( time() + DAY_IN_SECONDS, DAY_IN_SECONDS, UpdateEmitter::CHECK_HOOK, array(), WorkerRunner::GROUP );
				}
			}
		);

		add_action( FatalErrorPromotionJob::HOOK, array( $fatal_error_promotion_job, 'run' ) );
		add_action(
			'init',
			static function () {
				if ( ! as_has_scheduled_action( FatalErrorPromotionJob::HOOK, array(), WorkerRunner::GROUP ) ) {
					as_schedule_recurring_action( time() + 5 * MINUTE_IN_SECONDS, 5 * MINUTE_IN_SECONDS, FatalErrorPromotionJob::HOOK, array(), WorkerRunner::GROUP );
				}
			}
		);

		( new FatalErrorMarkerWriter() )->register();

		// Administration (M02, migrated into the hub at M04.1/ADR-0020):
		// capability-gated event catalog and rule builder screens, now
		// Events/Rules tabs of the single administration hub.
		$this->event_catalog_page = new EventCatalogPage( $this->event_registry );
		$this->hub_tab_registry->register(
			new Tab( 'events', __( 'Events', 'universal-telegram' ), CapabilityRegistrar::MANAGE_AUTOMATIONS, array( $this->event_catalog_page, 'render_tab_content' ) )
		);

		$this->rule_builder_page = new RuleBuilderPage(
			$this->notification_rule_repository,
			$this->event_registry,
			$this->bot_profile_repository,
			$this->destination_repository
		);
		$this->hub_tab_registry->register(
			new Tab( 'rules', __( 'Rules', 'universal-telegram' ), CapabilityRegistrar::MANAGE_AUTOMATIONS, array( $this->rule_builder_page, 'render_tab_content' ) )
		);

		$this->rule_builder_request_handler = new RuleBuilderRequestHandler( $this->notification_rule_repository );
		add_action( 'admin_post_' . RuleBuilderRequestHandler::ADMIN_POST_ACTION, array( $this->rule_builder_request_handler, 'handle_request' ) );

		if ( defined( 'UNIVERSAL_TELEGRAM_PLUGIN_FILE' ) ) {
			( new PluginActionLinks( plugin_basename( UNIVERSAL_TELEGRAM_PLUGIN_FILE ) ) )->register();
		}

		$rule_simulator = new RuleSimulator( $this->notification_rule_repository, $this->event_registry, $this->dispatch_log_repository, $notification_dispatcher );

		$this->rule_simulator_page = new RuleSimulatorPage( $rule_simulator, $this->event_registry );
		$this->hub_tab_registry->register(
			new Tab( RuleSimulatorPage::TAB_ID, __( 'Simulator', 'universal-telegram' ), CapabilityRegistrar::MANAGE_AUTOMATIONS, array( $this->rule_simulator_page, 'render_tab_content' ) )
		);

		$this->event_history_page = new EventHistoryPage( $this->schema_health );
		$this->hub_tab_registry->register(
			new Tab( EventHistoryPage::TAB_ID, __( 'Event History', 'universal-telegram' ), CapabilityRegistrar::MANAGE_AUTOMATIONS, array( $this->event_history_page, 'render_tab_content' ) )
		);

		// M11A visitor activity digest (docs/plans/m11a-visitor-activity-digests-plan-v1.md):
		// the shared is_active()/eligibility gate, constructed once and
		// reused by the settings page, the suppression guard, and the
		// counter increment/sweep wired further below.
		$this->digest_eligibility = new DigestEligibility( $settings, $this->bot_profile_repository, $this->destination_repository, $this->conversation_repository );
		$this->digest_eligibility->register();

		$this->visitor_tracking_page = new VisitorTrackingPage( $settings, $this->bot_profile_repository, $this->digest_eligibility );
		$this->hub_tab_registry->register(
			new Tab( VisitorTrackingPage::TAB_ID, __( 'Visitor Tracking', 'universal-telegram' ), CapabilityRegistrar::MANAGE, array( $this->visitor_tracking_page, 'render_tab_content' ) )
		);
		add_action( 'admin_post_' . VisitorTrackingPage::ADMIN_POST_ACTION, array( $this->visitor_tracking_page, 'handle_request' ) );

		// Settings (M04.1 plan §6): plugin-wide configuration that
		// previously had no admin UI at all. Registered second-to-last;
		// 'diagnostics' is registered last of all, so display order
		// matches the plan's mapping (M04.1 plan §3).
		$this->settings_page = new SettingsPage( $settings );
		$this->hub_tab_registry->register(
			new Tab( SettingsPage::TAB_ID, __( 'Settings', 'universal-telegram' ), CapabilityRegistrar::MANAGE, array( $this->settings_page, 'render_tab_content' ) )
		);
		add_action( 'admin_post_' . SettingsPage::ADMIN_POST_ACTION, array( $this->settings_page, 'handle_request' ) );

		// Operator identity mappings (M07, docs/adr/0026): the manual
		// WordPress-user <-> Telegram numeric-id mapping every operator
		// must have before their first Telegram reply is accepted.
		// MANAGE-gated, since creating a mapping grants inbound Telegram
		// operator-acting trust.
		$this->operator_identity_page            = new OperatorIdentityPage( $this->operator_identity_repository );
		$this->operator_identity_request_handler = new OperatorIdentityRequestHandler( $this->operator_identity_repository );
		$this->hub_tab_registry->register(
			new Tab( OperatorIdentityPage::TAB_ID, __( 'Operator Identities', 'universal-telegram' ), CapabilityRegistrar::MANAGE, array( $this->operator_identity_page, 'render_tab_content' ) )
		);
		add_action( 'admin_post_' . OperatorIdentityRequestHandler::ADMIN_POST_ACTION, array( $this->operator_identity_request_handler, 'handle_request' ) );

		// Operator conversation-workflow actions (M07, docs/adr/0026):
		// availability now, assignment/lifecycle/notes/deletion added by
		// later M07 work packages onto the same single handler. No Hub tab
		// of its own — reached only via forms on the operator inbox tab.
		$this->conversation_action_handler = new ConversationActionHandler(
			$this->operator_availability_repository,
			$this->operator_identity_repository,
			$this->conversation_repository,
			$this->conversation_note_repository,
			$this->conversation_purge_service,
			$this->audit_logger
		);
		add_action( 'admin_post_' . ConversationActionHandler::ADMIN_POST_ACTION, array( $this->conversation_action_handler, 'handle_request' ) );

		// Conversation-detail draft review panel (M09, docs/adr/0028
		// decision 6): the only Administration\AI\* class permitted to
		// write reviewed/approved/discarded. Constructed here, before
		// ConversationDetailPage, since it is composed directly into that
		// page's render.
		$ai_conversation_draft_panel = new ConversationDraftPanel( $this->ai_draft_repository, $this->ai_provider_repository );
		add_action( 'admin_post_' . ConversationDraftPanel::ADMIN_POST_ACTION, array( $ai_conversation_draft_panel, 'handle_request' ) );

		// Operator inbox + detail view (M07, docs/adr/0026): unread badge,
		// own-availability control, status-filtered/paginated list, and the
		// message/note detail view with mark-seen-on-view.
		$this->conversation_detail_page = new ConversationDetailPage(
			$this->conversation_repository,
			$this->message_repository,
			$this->conversation_note_repository,
			$this->operator_identity_repository,
			$ai_conversation_draft_panel
		);
		$this->conversation_inbox_page  = new ConversationInboxPage(
			$this->conversation_repository,
			$this->operator_identity_repository,
			$this->operator_availability_repository,
			$this->conversation_detail_page
		);
		$this->hub_tab_registry->register(
			new Tab( ConversationInboxPage::TAB_ID, __( 'Conversations', 'universal-telegram' ), CapabilityRegistrar::MANAGE_CONVERSATIONS, array( $this->conversation_inbox_page, 'render_tab_content' ) )
		);

		// AI draft assistant (M09, docs/adr/0028): operator-assist-only
		// provider configuration and visitor-disclosure text. Registered
		// after Conversations, before 'diagnostics' (which stays last).
		$this->ai_settings_page = new AISettingsPage( $this->ai_provider_repository );
		$this->hub_tab_registry->register(
			new Tab( AISettingsPage::TAB_ID, __( 'AI', 'universal-telegram' ), CapabilityRegistrar::MANAGE, array( $this->ai_settings_page, 'render_tab_content' ) )
		);
		add_action( 'admin_post_' . AISettingsPage::ACTION_SAVE_SETTINGS, array( $this->ai_settings_page, 'handle_save_settings' ) );
		add_action( 'admin_post_' . AISettingsPage::ACTION_SET_CREDENTIAL, array( $this->ai_settings_page, 'handle_set_credential' ) );
		add_action( 'admin_post_' . AISettingsPage::ACTION_DELETE_CREDENTIAL, array( $this->ai_settings_page, 'handle_delete_credential' ) );
		add_action( 'admin_post_' . AISettingsPage::ACTION_BUMP_ACK, array( $this->ai_settings_page, 'handle_bump_ack' ) );

		$this->approved_content_repository = new ApprovedContentRepository( $this->message_repository );
		$this->approved_content_page       = new ApprovedContentPage( $this->approved_content_repository );
		$this->hub_tab_registry->register(
			new Tab( ApprovedContentPage::TAB_ID, __( 'AI Content', 'universal-telegram' ), CapabilityRegistrar::MANAGE, array( $this->approved_content_page, 'render_tab_content' ) )
		);
		add_action( 'admin_post_' . ApprovedContentPage::ADMIN_POST_ACTION, array( $this->approved_content_page, 'handle_request' ) );

		// AI draft generation queue worker + stale-lease recovery sweep
		// (M09, docs/adr/0028 decisions 4–5). No Hub tab of its own — reached
		// only via the queue and the recurring sweep action.
		$prompt_builder   = new PromptBuilder( $this->message_repository, $this->approved_content_repository );
		$ai_draft_handler = new AIDraftGenerationHandler(
			$this->ai_draft_repository,
			$this->ai_provider_repository,
			$prompt_builder,
			$this->circuit_breaker,
			new AiFailureClassifier(),
			new RetryPolicy()
		);
		$this->handler_registry->register( AIDraftGenerationHandler::JOB_TYPE, array( $ai_draft_handler, 'handle_job' ) );

		$ai_lease_sweep = new AiDraftLeaseSweep( $this->ai_draft_repository );
		add_action( AiDraftLeaseSweep::JOB_TYPE, array( $ai_lease_sweep, 'run' ) );
		add_action( 'init', array( $ai_lease_sweep, 'register' ) );

		// Operator draft-request endpoint (M09, docs/adr/0028 decisions 1
		// and 5): the only path that ever enqueues an ai_draft_generate job.
		$ai_draft_request_handler = new DraftRequestHandler(
			$this->ai_draft_repository,
			$this->ai_provider_repository,
			$this->conversation_repository,
			$this->dispatcher
		);
		add_action( 'admin_post_' . DraftRequestHandler::ADMIN_POST_ACTION, array( $ai_draft_request_handler, 'handle_request' ) );

		$this->hub_tab_registry->register(
			new Tab( 'diagnostics', __( 'Diagnostics', 'universal-telegram' ), CapabilityRegistrar::MANAGE, array( $this->diagnostics_page, 'render_tab_content' ) )
		);

		// Legacy URL compatibility (M04.1 plan §5, ADR-0020): every
		// retired admin page slug stays permanently reachable, redirecting
		// only an authorized GET request (302, never 301) to its
		// equivalent Hub tab.
		add_action( 'admin_menu', array( new LegacyUrlRedirector(), 'register' ) );

		$retention_cleanup = new RetentionCleanup(
			$this->schema_health,
			(int) $settings_values['event_retention_days'],
			(int) $settings_values['dispatch_log_retention_days'],
			(int) $settings_values['fatal_marker_retention_days']
		);
		add_action( RetentionCleanup::HOOK, array( $retention_cleanup, 'run' ) );

		add_action(
			'init',
			static function () {
				if ( ! as_has_scheduled_action( RetentionCleanup::HOOK, array(), WorkerRunner::GROUP ) ) {
					as_schedule_recurring_action( time() + DAY_IN_SECONDS, DAY_IN_SECONDS, RetentionCleanup::HOOK, array(), WorkerRunner::GROUP );
				}
			}
		);
	}

	/**
	 * The current request's schema-availability state. Available only
	 * after init() has run.
	 */
	public function schema_health(): ?SchemaHealth {
		return $this->schema_health;
	}

	/**
	 * The audit log writer. Available only after init() has run.
	 */
	public function audit_logger(): ?AuditLogger {
		return $this->audit_logger;
	}

	/**
	 * The audit log reader. Available only after init() has run.
	 */
	public function audit_log_repository(): ?AuditLogRepository {
		return $this->audit_log_repository;
	}

	/**
	 * The WooCommerce-presence detector. Available only after init() has
	 * run.
	 */
	public function woocommerce_support(): ?WooCommerceSupport {
		return $this->woocommerce_support;
	}

	/**
	 * The credential vault. Available only after init() has run.
	 */
	public function credential_vault(): ?CredentialVault {
		return $this->credential_vault;
	}

	/**
	 * The capability registrar. Available only after init() has run.
	 */
	public function capability_registrar(): ?CapabilityRegistrar {
		return $this->capability_registrar;
	}

	/**
	 * The internal job-type-to-handler map. Available only after init()
	 * has run.
	 */
	public function handler_registry(): ?HandlerRegistry {
		return $this->handler_registry;
	}

	/**
	 * The job dispatcher. Available only after init() has run.
	 */
	public function dispatcher(): ?Dispatcher {
		return $this->dispatcher;
	}

	/**
	 * The queue's pending/failed action counts. Available only after
	 * init() has run.
	 */
	public function queue_health(): ?QueueHealth {
		return $this->queue_health;
	}

	/**
	 * The worker runner. Available only after init() has run.
	 */
	public function worker_runner(): ?WorkerRunner {
		return $this->worker_runner;
	}

	/**
	 * The diagnostics admin page. Available only after init() has run.
	 */
	public function diagnostics_page(): ?DiagnosticsPage {
		return $this->diagnostics_page;
	}

	/**
	 * The administration hub's tab registry. Available only after init()
	 * has run.
	 */
	public function hub_tab_registry(): ?TabRegistry {
		return $this->hub_tab_registry;
	}

	/**
	 * The administration hub shell page. Available only after init() has
	 * run.
	 */
	public function hub_page(): ?HubPage {
		return $this->hub_page;
	}

	/**
	 * The bounded diagnostic self-test. Available only after init() has
	 * run.
	 */
	public function self_test(): ?SelfTest {
		return $this->self_test;
	}

	/**
	 * The bot profile repository. Available only after init() has run.
	 */
	public function bot_profile_repository(): ?BotProfileRepository {
		return $this->bot_profile_repository;
	}

	/**
	 * The destination repository. Available only after init() has run.
	 */
	public function destination_repository(): ?DestinationRepository {
		return $this->destination_repository;
	}

	/**
	 * The Telegram Bot API client. Available only after init() has run.
	 */
	public function telegram_api_client(): ?TelegramApiClient {
		return $this->telegram_api_client;
	}

	/**
	 * The outbound message repository. Available only after init() has run.
	 */
	public function outbound_message_repository(): ?OutboundMessageRepository {
		return $this->outbound_message_repository;
	}

	/**
	 * The outbound message dispatcher. Available only after init() has run.
	 */
	public function message_dispatcher(): ?MessageDispatcher {
		return $this->message_dispatcher;
	}

	/**
	 * The inbound update repository. Available only after init() has run.
	 */
	public function update_repository(): ?UpdateRepository {
		return $this->update_repository;
	}

	/**
	 * The webhook secret verifier. Available only after init() has run.
	 */
	public function webhook_secret_verifier(): ?WebhookSecretVerifier {
		return $this->webhook_secret_verifier;
	}

	/**
	 * The inbound webhook REST controller. Available only after init() has run.
	 */
	public function webhook_controller(): ?WebhookController {
		return $this->webhook_controller;
	}

	/**
	 * Administrative-bot command dispatcher. Available only after init() has run.
	 */
	public function bot_command_dispatcher(): ?BotCommandDispatcher {
		return $this->bot_command_dispatcher;
	}

	/**
	 * The per-bot/per-destination rate limiter. Available only after init() has run.
	 */
	public function rate_limiter(): ?RateLimiter {
		return $this->rate_limiter;
	}

	/**
	 * Conversation persistence. Available only after init() has run.
	 */
	public function conversation_repository(): ?ConversationRepository {
		return $this->conversation_repository;
	}

	/**
	 * Conversation message persistence. Available only after init() has run.
	 */
	public function message_repository(): ?MessageRepository {
		return $this->message_repository;
	}

	/**
	 * Operator identity mapping persistence. Available only after init() has run.
	 */
	public function operator_identity_repository(): ?OperatorIdentityRepository {
		return $this->operator_identity_repository;
	}

	/**
	 * Operator availability persistence. Available only after init() has run.
	 */
	public function operator_availability_repository(): ?OperatorAvailabilityRepository {
		return $this->operator_availability_repository;
	}

	/**
	 * Conversation internal note persistence. Available only after init() has run.
	 */
	public function conversation_note_repository(): ?ConversationNoteRepository {
		return $this->conversation_note_repository;
	}

	/**
	 * The operator identity mapping admin page. Available only after init() has run.
	 */
	public function operator_identity_page(): ?OperatorIdentityPage {
		return $this->operator_identity_page;
	}

	/**
	 * The operator identity mapping request handler. Available only after init() has run.
	 */
	public function operator_identity_request_handler(): ?OperatorIdentityRequestHandler {
		return $this->operator_identity_request_handler;
	}

	/**
	 * The operator conversation-workflow action handler. Available only after init() has run.
	 */
	public function conversation_action_handler(): ?ConversationActionHandler {
		return $this->conversation_action_handler;
	}

	/**
	 * The operator conversation detail page. Available only after init() has run.
	 */
	public function conversation_detail_page(): ?ConversationDetailPage {
		return $this->conversation_detail_page;
	}

	/**
	 * The operator conversation inbox page. Available only after init() has run.
	 */
	public function conversation_inbox_page(): ?ConversationInboxPage {
		return $this->conversation_inbox_page;
	}

	/**
	 * The public visitor conversation REST controller. Available only after init() has run.
	 */
	public function conversations_controller(): ?ConversationsController {
		return $this->conversations_controller;
	}

	/**
	 * Idempotent Telegram forum-topic creation dispatch. Available only after init() has run.
	 */
	public function topic_creation_dispatcher(): ?TopicCreationDispatcher {
		return $this->topic_creation_dispatcher;
	}

	/**
	 * The per-bot/per-destination circuit breaker. Available only after init() has run.
	 */
	public function circuit_breaker(): ?CircuitBreaker {
		return $this->circuit_breaker;
	}

	/**
	 * The queue-health alert computation. Available only after init() has run.
	 */
	public function queue_health_alert(): ?QueueHealthAlert {
		return $this->queue_health_alert;
	}

	/**
	 * The retention cleanup handler. Available only after init() has run.
	 */
	public function retention_cleanup_handler(): ?RetentionCleanupHandler {
		return $this->retention_cleanup_handler;
	}

	/**
	 * The conversation retention cleanup handler. Available only after init() has run.
	 */
	public function conversation_retention_cleanup_handler(): ?ConversationRetentionCleanupHandler {
		return $this->conversation_retention_cleanup_handler;
	}

	/**
	 * The shared conversation purge service. Available only after init() has run.
	 */
	public function conversation_purge_service(): ?ConversationPurgeService {
		return $this->conversation_purge_service;
	}

	/**
	 * The webhook registration/rotation coordinator. Available only after init() has run.
	 */
	public function webhook_registration_coordinator(): ?WebhookRegistrationCoordinator {
		return $this->webhook_registration_coordinator;
	}

	/**
	 * The bot/destination management admin page. Available only after init() has run.
	 */
	public function bot_management_page(): ?BotManagementPage {
		return $this->bot_management_page;
	}

	/**
	 * The bot/destination management request handler. Available only after init() has run.
	 */
	public function bot_management_controller(): ?BotManagementController {
		return $this->bot_management_controller;
	}

	/**
	 * The current request's event registry. Available only after init() has run.
	 */
	public function event_registry(): ?Registry {
		return $this->event_registry;
	}

	/**
	 * The internal event ingestion orchestrator. Available only after init() has run.
	 */
	public function event_dispatcher(): ?EventDispatcher {
		return $this->event_dispatcher;
	}

	/**
	 * The safety-wrapped event emission façade. Available only after init() has run.
	 */
	public function event_emitter(): ?EventEmitter {
		return $this->event_emitter;
	}

	/**
	 * The notification rule repository. Available only after init() has run.
	 */
	public function notification_rule_repository(): ?NotificationRuleRepository {
		return $this->notification_rule_repository;
	}

	/**
	 * The M11A visitor digest shared eligibility gate. Available only after init() has run.
	 */
	public function digest_eligibility(): ?DigestEligibility {
		return $this->digest_eligibility;
	}

	/**
	 * The idempotent dispatch-log repository. Available only after init() has run.
	 */
	public function dispatch_log_repository(): ?DispatchLogRepository {
		return $this->dispatch_log_repository;
	}

	/**
	 * The event history repository. Available only after init() has run.
	 */
	public function event_history_repository(): ?EventHistoryRepository {
		return $this->event_history_repository;
	}

	/**
	 * The event catalog admin page. Available only after init() has run.
	 */
	public function event_catalog_page(): ?EventCatalogPage {
		return $this->event_catalog_page;
	}

	/**
	 * The rule builder admin page. Available only after init() has run.
	 */
	public function rule_builder_page(): ?RuleBuilderPage {
		return $this->rule_builder_page;
	}

	/**
	 * The rule builder request handler. Available only after init() has run.
	 */
	public function rule_builder_request_handler(): ?RuleBuilderRequestHandler {
		return $this->rule_builder_request_handler;
	}

	/**
	 * The rule simulator admin page. Available only after init() has run.
	 */
	public function rule_simulator_page(): ?RuleSimulatorPage {
		return $this->rule_simulator_page;
	}

	/**
	 * The event history browser admin page. Available only after init() has run.
	 */
	public function event_history_page(): ?EventHistoryPage {
		return $this->event_history_page;
	}

	/**
	 * The visitor tracking settings admin page. Available only after
	 * init() has run.
	 */
	public function visitor_tracking_page(): ?VisitorTrackingPage {
		return $this->visitor_tracking_page;
	}
}
