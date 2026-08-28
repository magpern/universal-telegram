<?php
/**
 * Composition root.
 *
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Core;

use UniversalTelegram\Administration\Automations\EventCatalogPage;
use UniversalTelegram\Administration\Automations\EventHistoryPage;
use UniversalTelegram\Administration\Automations\NotificationTester;
use UniversalTelegram\Administration\Automations\NotificationTesterPage;
use UniversalTelegram\Administration\Automations\PreviewRenderer;
use UniversalTelegram\Administration\Automations\RuleBuilderPage;
use UniversalTelegram\Administration\Automations\RuleBuilderRequestHandler;
use UniversalTelegram\Administration\Cli\LegacyChatPurgeCommand;
use UniversalTelegram\Administration\Diagnostics\DiagnosticsPage;
use UniversalTelegram\Administration\Diagnostics\DiagnosticsReport;
use UniversalTelegram\Administration\Diagnostics\SelfTest;
use UniversalTelegram\Administration\Hub\AreaPage;
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
use UniversalTelegram\Audit\AuditLogger;
use UniversalTelegram\Audit\AuditLogRepository;
use UniversalTelegram\Automations\DispatchLogRepository;
use UniversalTelegram\Automations\EventCountAggregator;
use UniversalTelegram\Automations\Intelligence\AlertEvaluator;
use UniversalTelegram\Automations\Intelligence\AlertRepository;
use UniversalTelegram\Automations\Intelligence\AlertSweep;
use UniversalTelegram\Automations\Intelligence\IntelligenceSettings;
use UniversalTelegram\Automations\Intelligence\IntelligenceStateRepository;
use UniversalTelegram\Automations\NotificationDispatcher;
use UniversalTelegram\Automations\NotificationRuleRepository;
use UniversalTelegram\Automations\RuleEvaluator;
use UniversalTelegram\Automations\TemplateRenderer;
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
use UniversalTelegram\Integrations\WooCommerce\Events\CartEventEmitter;
use UniversalTelegram\Integrations\WooCommerce\Events\CheckoutEventEmitter;
use UniversalTelegram\Integrations\WooCommerce\Events\CouponEventEmitter;
use UniversalTelegram\Integrations\WooCommerce\Events\OrderEventEmitter;
use UniversalTelegram\Integrations\WooCommerce\Events\StockEventEmitter;
use UniversalTelegram\Events\Registry;
use UniversalTelegram\Events\RetentionCleanup;
use UniversalTelegram\Integrations\WooCommerce\WooCommerceCommandQueryService;
use UniversalTelegram\Integrations\WooCommerce\WooCommerceSupport;
use UniversalTelegram\Persistence\LegacyChatPurge;
use UniversalTelegram\Persistence\MigrationFailedException;
use UniversalTelegram\Persistence\MigrationLock;
use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Privacy\Redactor;
use UniversalTelegram\Queue\Dispatcher;
use UniversalTelegram\Queue\HandlerRegistry;
use UniversalTelegram\Queue\QueueHealth;
use UniversalTelegram\Queue\RetryPolicy;
use UniversalTelegram\Queue\WorkerRunner;
use UniversalTelegram\Telegram\Client\TelegramApiClient;
use UniversalTelegram\Telegram\Client\TelegramFailureClassifier;
use UniversalTelegram\Telegram\Commands\BotCommandDispatcher;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;
use UniversalTelegram\Telegram\Configuration\DestinationEligibility;
use UniversalTelegram\Telegram\Configuration\DestinationRepository;
use UniversalTelegram\Telegram\Configuration\WebhookRegistrationCoordinator;
use UniversalTelegram\Telegram\Inbound\UpdateRepository;
use UniversalTelegram\Telegram\Inbound\WebhookController;
use UniversalTelegram\Telegram\Inbound\WebhookSecretVerifier;
use UniversalTelegram\Telegram\Outbound\DeadLetterDismisser;
use UniversalTelegram\Telegram\Outbound\MessageDispatcher;
use UniversalTelegram\Telegram\Outbound\OutboundMessageRepository;
use UniversalTelegram\Telegram\Outbound\RetentionCleanupHandler;
use UniversalTelegram\Telegram\Outbound\SendMessageHandler;
use UniversalTelegram\Telegram\Outbound\UnresolvedOutboundAbandoner;
use UniversalTelegram\Telegram\Reliability\CircuitBreaker;
use UniversalTelegram\Telegram\Reliability\QueueHealthAlert;
use UniversalTelegram\Telegram\Reliability\RateLimiter;
use UniversalTelegram\Telegram\Topics\ForumTopicService;
use UniversalTelegram\SupportChatAdapter\Auth\NonceReplayRepository;
use UniversalTelegram\SupportChatAdapter\Auth\NonceReplaySweep;
use UniversalTelegram\SupportChatAdapter\Auth\OwnKeyManager;
use UniversalTelegram\SupportChatAdapter\Auth\PairingService;
use UniversalTelegram\SupportChatAdapter\Auth\PeerRepository;
use UniversalTelegram\SupportChatAdapter\Auth\SignatureSigner;
use UniversalTelegram\SupportChatAdapter\Auth\SignatureVerifier;
use UniversalTelegram\SupportChatAdapter\ChannelBindingRepository;
use UniversalTelegram\SupportChatAdapter\DeliveryIdempotencyRepository;
use UniversalTelegram\SupportChatAdapter\Diagnostics\AdapterStatusPage;
use UniversalTelegram\SupportChatAdapter\DiscoveryClient;
use UniversalTelegram\SupportChatAdapter\Identity\OperatorIdentityMapRepository;
use UniversalTelegram\SupportChatAdapter\Inbound\InboundAdapterBridge;
use UniversalTelegram\SupportChatAdapter\Inbound\SupportChatContractClient;
use UniversalTelegram\SupportChatAdapter\Outbound\BackfillService;
use UniversalTelegram\SupportChatAdapter\Outbound\DeliverMessageService;
use UniversalTelegram\SupportChatAdapter\Outbound\EnsureChannelCaseService;
use UniversalTelegram\SupportChatAdapter\Outbound\NotifyOperatorsService;
use UniversalTelegram\SupportChatAdapter\Outbound\OutboundContractController;
use UniversalTelegram\SupportChatAdapter\Pairing\PairingController;

/**
 * Singleton composition root. Constructs and wires every service by hand
 * inside init(); no dependency-injection container. Every service is always
 * constructed and always wired, regardless of schema availability;
 * individual database-touching operations check SchemaHealth at their own
 * point of use (docs/adr/0007).
 *
 * Since ADR-0044 this plugin is a Telegram transport / Support Chat adapter
 * only: no legacy website chat, no conversation store, no AI, no
 * migration/cutover.
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
	 * The schema_health instance, set by init().
	 *
	 * @var SchemaHealth|null
	 */
	private ?SchemaHealth $schema_health = null;

	/**
	 * The audit_logger instance, set by init().
	 *
	 * @var AuditLogger|null
	 */
	private ?AuditLogger $audit_logger = null;

	/**
	 * The audit_log_repository instance, set by init().
	 *
	 * @var AuditLogRepository|null
	 */
	private ?AuditLogRepository $audit_log_repository = null;

	/**
	 * The woo_commerce_support instance, set by init().
	 *
	 * @var WooCommerceSupport|null
	 */
	private ?WooCommerceSupport $woocommerce_support = null;

	/**
	 * The credential_vault instance, set by init().
	 *
	 * @var CredentialVault|null
	 */
	private ?CredentialVault $credential_vault = null;

	/**
	 * The capability_registrar instance, set by init().
	 *
	 * @var CapabilityRegistrar|null
	 */
	private ?CapabilityRegistrar $capability_registrar = null;

	/**
	 * The handler_registry instance, set by init().
	 *
	 * @var HandlerRegistry|null
	 */
	private ?HandlerRegistry $handler_registry = null;

	/**
	 * The dispatcher instance, set by init().
	 *
	 * @var Dispatcher|null
	 */
	private ?Dispatcher $dispatcher = null;

	/**
	 * The queue_health instance, set by init().
	 *
	 * @var QueueHealth|null
	 */
	private ?QueueHealth $queue_health = null;

	/**
	 * The worker_runner instance, set by init().
	 *
	 * @var WorkerRunner|null
	 */
	private ?WorkerRunner $worker_runner = null;

	/**
	 * The diagnostics_page instance, set by init().
	 *
	 * @var DiagnosticsPage|null
	 */
	private ?DiagnosticsPage $diagnostics_page = null;

	/**
	 * The tab_registry instance, set by init().
	 *
	 * @var TabRegistry|null
	 */
	private ?TabRegistry $hub_tab_registry = null;

	/**
	 * The hub_page instance, set by init().
	 *
	 * @var HubPage|null
	 */
	private ?HubPage $hub_page = null;

	/**
	 * The self_test instance, set by init().
	 *
	 * @var SelfTest|null
	 */
	private ?SelfTest $self_test = null;

	/**
	 * The bot_profile_repository instance, set by init().
	 *
	 * @var BotProfileRepository|null
	 */
	private ?BotProfileRepository $bot_profile_repository = null;

	/**
	 * The destination_repository instance, set by init().
	 *
	 * @var DestinationRepository|null
	 */
	private ?DestinationRepository $destination_repository = null;

	/**
	 * The telegram_api_client instance, set by init().
	 *
	 * @var TelegramApiClient|null
	 */
	private ?TelegramApiClient $telegram_api_client = null;

	/**
	 * The outbound_message_repository instance, set by init().
	 *
	 * @var OutboundMessageRepository|null
	 */
	private ?OutboundMessageRepository $outbound_message_repository = null;

	/**
	 * The message_dispatcher instance, set by init().
	 *
	 * @var MessageDispatcher|null
	 */
	private ?MessageDispatcher $message_dispatcher = null;

	/**
	 * The update_repository instance, set by init().
	 *
	 * @var UpdateRepository|null
	 */
	private ?UpdateRepository $update_repository = null;

	/**
	 * The webhook_secret_verifier instance, set by init().
	 *
	 * @var WebhookSecretVerifier|null
	 */
	private ?WebhookSecretVerifier $webhook_secret_verifier = null;

	/**
	 * The webhook_controller instance, set by init().
	 *
	 * @var WebhookController|null
	 */
	private ?WebhookController $webhook_controller = null;

	/**
	 * The bot_command_dispatcher instance, set by init().
	 *
	 * @var BotCommandDispatcher|null
	 */
	private ?BotCommandDispatcher $bot_command_dispatcher = null;

	/**
	 * The rate_limiter instance, set by init().
	 *
	 * @var RateLimiter|null
	 */
	private ?RateLimiter $rate_limiter = null;

	/**
	 * The circuit_breaker instance, set by init().
	 *
	 * @var CircuitBreaker|null
	 */
	private ?CircuitBreaker $circuit_breaker = null;

	/**
	 * The queue_health_alert instance, set by init().
	 *
	 * @var QueueHealthAlert|null
	 */
	private ?QueueHealthAlert $queue_health_alert = null;

	/**
	 * The retention_cleanup_handler instance, set by init().
	 *
	 * @var RetentionCleanupHandler|null
	 */
	private ?RetentionCleanupHandler $retention_cleanup_handler = null;

	/**
	 * The webhook_registration_coordinator instance, set by init().
	 *
	 * @var WebhookRegistrationCoordinator|null
	 */
	private ?WebhookRegistrationCoordinator $webhook_registration_coordinator = null;

	/**
	 * The bot_management_page instance, set by init().
	 *
	 * @var BotManagementPage|null
	 */
	private ?BotManagementPage $bot_management_page = null;

	/**
	 * The bot_management_controller instance, set by init().
	 *
	 * @var BotManagementController|null
	 */
	private ?BotManagementController $bot_management_controller = null;

	/**
	 * The registry instance, set by init().
	 *
	 * @var Registry|null
	 */
	private ?Registry $event_registry = null;

	/**
	 * The event_dispatcher instance, set by init().
	 *
	 * @var EventDispatcher|null
	 */
	private ?EventDispatcher $event_dispatcher = null;

	/**
	 * The event_emitter instance, set by init().
	 *
	 * @var EventEmitter|null
	 */
	private ?EventEmitter $event_emitter = null;

	/**
	 * The notification_rule_repository instance, set by init().
	 *
	 * @var NotificationRuleRepository|null
	 */
	private ?NotificationRuleRepository $notification_rule_repository = null;

	/**
	 * The dispatch_log_repository instance, set by init().
	 *
	 * @var DispatchLogRepository|null
	 */
	private ?DispatchLogRepository $dispatch_log_repository = null;

	/**
	 * The event_history_repository instance, set by init().
	 *
	 * @var EventHistoryRepository|null
	 */
	private ?EventHistoryRepository $event_history_repository = null;

	/**
	 * The event_catalog_page instance, set by init().
	 *
	 * @var EventCatalogPage|null
	 */
	private ?EventCatalogPage $event_catalog_page = null;

	/**
	 * The rule_builder_page instance, set by init().
	 *
	 * @var RuleBuilderPage|null
	 */
	private ?RuleBuilderPage $rule_builder_page = null;

	/**
	 * The rule_builder_request_handler instance, set by init().
	 *
	 * @var RuleBuilderRequestHandler|null
	 */
	private ?RuleBuilderRequestHandler $rule_builder_request_handler = null;

	/**
	 * The notification_tester_page instance, set by init().
	 *
	 * @var NotificationTesterPage|null
	 */
	private ?NotificationTesterPage $notification_tester_page = null;

	/**
	 * The event_history_page instance, set by init().
	 *
	 * @var EventHistoryPage|null
	 */
	private ?EventHistoryPage $event_history_page = null;

	/**
	 * The channel_binding_repository instance, set by init().
	 *
	 * @var ChannelBindingRepository|null
	 */
	private ?ChannelBindingRepository $support_chat_adapter_bindings = null;

	/**
	 * The discovery_client instance, set by init().
	 *
	 * @var DiscoveryClient|null
	 */
	private ?DiscoveryClient $support_chat_adapter_discovery = null;

	/**
	 * The pairing_controller instance, set by init().
	 *
	 * @var PairingController|null
	 */
	private ?PairingController $support_chat_pairing_controller = null;

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
	 * Constructs and wires every service. Idempotent.
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
		$this->woocommerce_support  = new WooCommerceSupport();
		$this->credential_vault     = new CredentialVault();
		$this->capability_registrar = new CapabilityRegistrar();

		$this->handler_registry = new HandlerRegistry();
		$this->dispatcher       = new Dispatcher( $this->schema_health );
		$this->queue_health     = new QueueHealth();

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

		// Transport core.
		$this->bot_profile_repository      = new BotProfileRepository( $this->schema_health, $this->credential_vault );
		$this->destination_repository      = new DestinationRepository( $this->schema_health );
		$this->telegram_api_client         = new TelegramApiClient();
		$this->outbound_message_repository = new OutboundMessageRepository( $this->schema_health, $this->credential_vault );
		$unresolved_outbound_abandoner     = new UnresolvedOutboundAbandoner( $this->outbound_message_repository );
		$dead_letter_dismisser             = new DeadLetterDismisser( $this->outbound_message_repository, $this->audit_logger );
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
			$unresolved_outbound_abandoner,
			(int) $settings_values['telegram_rate_limit_fallback_wait_seconds'],
			(int) $settings_values['telegram_max_pending_seconds']
		);
		$this->handler_registry->register( MessageDispatcher::JOB_TYPE, array( $send_message_handler, 'handle_job' ) );

		$this->update_repository       = new UpdateRepository( $this->schema_health );
		$this->webhook_secret_verifier = new WebhookSecretVerifier( $this->bot_profile_repository, $this->audit_logger );

		$operator_identity_map = new OperatorIdentityMapRepository( $this->schema_health );

		// Events / notifications.
		$this->event_registry           = new Registry();
		$this->event_history_repository = new EventHistoryRepository( $this->schema_health, $this->event_registry, new Redactor() );

		$this->bot_command_dispatcher = new BotCommandDispatcher(
			$operator_identity_map,
			$this->queue_health,
			$this->event_history_repository,
			$this->woocommerce_support,
			new WooCommerceCommandQueryService(),
			$this->message_dispatcher,
			$this->destination_repository,
			$this->audit_logger
		);

		// Support Chat adapter.
		$adapter_enabled   = ! empty( $settings_values['support_chat_adapter_enabled'] );
		$adapter_bindings  = new ChannelBindingRepository( $this->schema_health );
		$adapter_discovery = new DiscoveryClient();

		$adapter_own_key     = new OwnKeyManager( $this->credential_vault );
		$adapter_peers       = new PeerRepository( $this->schema_health );
		$adapter_nonces      = new NonceReplayRepository( $this->schema_health );
		$adapter_signer      = new SignatureSigner( $adapter_own_key );
		$adapter_verifier    = new SignatureVerifier( $adapter_peers, $adapter_nonces );
		$adapter_pairing     = new PairingService( $adapter_peers, $this->audit_logger );
		$adapter_nonce_sweep = new NonceReplaySweep( $adapter_nonces );
		add_action( NonceReplaySweep::JOB_TYPE, array( $adapter_nonce_sweep, 'run' ) );
		$adapter_nonce_sweep->register();

		$adapter_sc_client = new SupportChatContractClient(
			$adapter_peers,
			$adapter_own_key,
			$adapter_discovery,
			$adapter_signer,
			$adapter_enabled
		);
		$adapter_inbound   = new InboundAdapterBridge(
			$adapter_bindings,
			$adapter_discovery,
			$adapter_sc_client,
			$operator_identity_map,
			$this->audit_logger,
			$adapter_enabled
		);

		$this->webhook_controller = new WebhookController(
			$this->schema_health,
			$this->bot_profile_repository,
			$this->webhook_secret_verifier,
			$this->update_repository,
			$this->bot_command_dispatcher,
			(int) $settings_values['telegram_webhook_max_body_bytes'],
			$adapter_inbound,
			$adapter_bindings,
			$adapter_sc_client
		);
		add_action( 'rest_api_init', array( $this->webhook_controller, 'register_routes' ) );

		$forum_topics          = new ForumTopicService( $this->bot_profile_repository, $this->telegram_api_client );
		$adapter_delivery_keys = new DeliveryIdempotencyRepository( $this->schema_health );
		$adapter_ensure        = new EnsureChannelCaseService(
			$adapter_bindings,
			$this->bot_profile_repository,
			$this->destination_repository,
			$forum_topics
		);
		$adapter_deliver       = new DeliverMessageService(
			$adapter_bindings,
			$adapter_delivery_keys,
			$this->outbound_message_repository,
			$this->dispatcher
		);
		$adapter_notify        = new NotifyOperatorsService( $adapter_deliver );
		$adapter_backfill      = new BackfillService( $adapter_deliver );
		$adapter_outbound      = new OutboundContractController(
			$adapter_discovery,
			$settings,
			$this->destination_repository,
			$adapter_ensure,
			$adapter_notify,
			$adapter_backfill,
			$adapter_deliver,
			$adapter_verifier,
			$adapter_peers
		);
		add_action( 'rest_api_init', array( $adapter_outbound, 'register_routes' ) );

		$this->support_chat_adapter_bindings   = $adapter_bindings;
		$this->support_chat_adapter_discovery  = $adapter_discovery;
		$this->support_chat_pairing_controller = new PairingController( $adapter_own_key, $adapter_peers, $adapter_pairing );

		// Guarded legacy-chat purge command (ADR-0044 §5).
		( new LegacyChatPurgeCommand( new LegacyChatPurge() ) )->register();

		// Transport-log retention.
		$this->retention_cleanup_handler = new RetentionCleanupHandler(
			$this->outbound_message_repository,
			(int) $settings_values['telegram_message_retention_days'],
			(int) $settings_values['telegram_delivery_log_retention_days']
		);
		add_action( RetentionCleanupHandler::HOOK, array( $this->retention_cleanup_handler, 'run' ) );
		add_action(
			'init',
			static function () {
				if ( ! as_has_scheduled_action( RetentionCleanupHandler::HOOK, array(), WorkerRunner::GROUP ) ) {
					as_schedule_recurring_action( time() + DAY_IN_SECONDS, DAY_IN_SECONDS, RetentionCleanupHandler::HOOK, array(), WorkerRunner::GROUP );
				}
			}
		);

		// Operator-identity-map cleanup on account deletion.
		add_action(
			'deleted_user',
			static function ( int $user_id ) use ( $operator_identity_map ): void {
				$operator_identity_map->delete_for_wp_user( $user_id );
			}
		);

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
			$this->audit_logger,
			new \UniversalTelegram\Telegram\Topics\ForumTopicRemoteDeleter(
				$this->bot_profile_repository,
				$this->destination_repository,
				$this->telegram_api_client
			),
			$unresolved_outbound_abandoner,
			$dead_letter_dismisser
		);
		add_action( 'admin_post_' . BotManagementController::ADMIN_POST_ACTION, array( $this->bot_management_controller, 'handle_request' ) );

		$telegram_form_fields      = new TelegramFormFields();
		$bot_setup_wizard_state    = new BotSetupWizardState( $this->bot_profile_repository, $this->destination_repository );
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
			$bot_setup_wizard_renderer
		);

		$this->notification_rule_repository = new NotificationRuleRepository( $this->schema_health, $this->event_registry );
		$this->dispatch_log_repository      = new DispatchLogRepository( $this->schema_health );

		$destination_eligibility = new DestinationEligibility( $this->bot_profile_repository, $this->destination_repository );
		$intelligence_settings   = new IntelligenceSettings( $settings );
		$alert_repository        = new AlertRepository( $this->schema_health );

		$diagnostics_report     = new DiagnosticsReport(
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
			(int) $settings_values['telegram_stale_pending_alert_seconds'],
			(int) $settings_values['telegram_webhook_rotation_max_pending_hours'],
			$intelligence_settings,
			$alert_repository
		);
		$this->diagnostics_page = new DiagnosticsPage(
			$diagnostics_report,
			$this->schema_health,
			$this->self_test,
			$this->queue_health_alert,
			(int) $settings_values['telegram_stale_pending_alert_seconds'],
			(int) $settings_values['telegram_webhook_rotation_max_pending_hours']
		);
		add_action( 'admin_notices', array( $this->diagnostics_page, 'render_admin_notice' ) );

		// Admin hub.
		$overview_page          = new OverviewPage();
		$this->hub_tab_registry = new TabRegistry();
		$this->hub_tab_registry->register(
			new Tab( OverviewPage::TAB_ID, __( 'Overview', 'universal-telegram' ), CapabilityRegistrar::MANAGE, array( $overview_page, 'render_tab_content' ) )
		);
		$this->hub_tab_registry->register(
			new Tab( 'bots', __( 'Bots', 'universal-telegram' ), CapabilityRegistrar::MANAGE, array( $this->bot_management_page, 'render_tab_content' ) )
		);
		$this->hub_page = new HubPage( $this->hub_tab_registry );
		add_action( 'admin_menu', array( $this->hub_page, 'register_menu' ) );

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
		}

		add_action(
			'init',
			function () {
				/**
				 * Fires so code can register event types against the shared registry.
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

		$this->event_catalog_page = new EventCatalogPage( $this->event_registry );

		$this->rule_builder_page            = new RuleBuilderPage(
			$this->notification_rule_repository,
			$this->event_registry,
			$this->bot_profile_repository,
			$this->destination_repository,
			$destination_eligibility,
			$settings,
			$this->woocommerce_support
		);
		$this->rule_builder_request_handler = new RuleBuilderRequestHandler( $this->notification_rule_repository );
		add_action( 'admin_post_' . RuleBuilderRequestHandler::ADMIN_POST_ACTION, array( $this->rule_builder_request_handler, 'handle_request' ) );
		add_action( 'admin_post_' . RuleBuilderPage::INTELLIGENCE_ADMIN_POST_ACTION, array( $this->rule_builder_page, 'handle_intelligence_settings_request' ) );
		add_action( 'admin_post_' . RuleBuilderPage::PREVIEW_ADMIN_POST_ACTION, array( $this->rule_builder_page, 'handle_preview_request' ) );

		if ( defined( 'UNIVERSAL_TELEGRAM_PLUGIN_FILE' ) ) {
			( new PluginActionLinks( plugin_basename( UNIVERSAL_TELEGRAM_PLUGIN_FILE ) ) )->register();
		}

		$notification_tester            = new NotificationTester(
			$rule_evaluator,
			$this->notification_rule_repository,
			$this->bot_profile_repository,
			$this->destination_repository,
			$this->event_registry,
			new PreviewRenderer( $this->event_registry )
		);
		$this->notification_tester_page = new NotificationTesterPage(
			$notification_tester,
			$this->notification_rule_repository,
			$this->event_registry,
			$this->bot_profile_repository,
			$this->destination_repository,
			$this->woocommerce_support
		);

		$this->event_history_page = new EventHistoryPage( $this->schema_health );

		$notifications_activity_area = new AreaPage(
			'notifications-activity',
			__( 'Notifications & activity', 'universal-telegram' ),
			array(
				new Tab( RuleBuilderPage::TAB_ID, __( 'Notifications', 'universal-telegram' ), CapabilityRegistrar::MANAGE_AUTOMATIONS, array( $this->rule_builder_page, 'render_tab_content' ) ),
				new Tab( NotificationTesterPage::TAB_ID, __( 'Test notifications', 'universal-telegram' ), CapabilityRegistrar::MANAGE_AUTOMATIONS, array( $this->notification_tester_page, 'render_tab_content' ) ),
				new Tab( 'events', __( 'Events', 'universal-telegram' ), CapabilityRegistrar::MANAGE_AUTOMATIONS, array( $this->event_catalog_page, 'render_tab_content' ) ),
				new Tab( EventHistoryPage::TAB_ID, __( 'Event History', 'universal-telegram' ), CapabilityRegistrar::MANAGE_AUTOMATIONS, array( $this->event_history_page, 'render_tab_content' ) ),
			)
		);
		$this->hub_tab_registry->register(
			new Tab(
				'notifications-activity',
				__( 'Notifications & activity', 'universal-telegram' ),
				CapabilityRegistrar::MANAGE_AUTOMATIONS,
				array( $notifications_activity_area, 'render_tab_content' ),
				array( $notifications_activity_area, 'is_accessible' )
			)
		);

		$settings_page = new SettingsPage( $settings );
		add_action( 'admin_post_' . SettingsPage::ADMIN_POST_ACTION, array( $settings_page, 'handle_request' ) );
		$this->hub_tab_registry->register(
			new Tab( SettingsPage::TAB_ID, __( 'Settings', 'universal-telegram' ), CapabilityRegistrar::MANAGE, array( $settings_page, 'render_tab_content' ) )
		);

		$this->hub_tab_registry->register(
			new Tab( 'diagnostics', __( 'Diagnostics', 'universal-telegram' ), CapabilityRegistrar::MANAGE, array( $this->diagnostics_page, 'render_tab_content' ) )
		);

		$adapter_status_page = new AdapterStatusPage(
			$settings,
			$this->support_chat_adapter_discovery,
			$this->support_chat_adapter_bindings,
			$this->bot_profile_repository,
			$destination_eligibility
		);
		$this->hub_tab_registry->register(
			new Tab(
				AdapterStatusPage::TAB_ID,
				__( 'Support Chat adapter', 'universal-telegram' ),
				CapabilityRegistrar::MANAGE,
				array( $adapter_status_page, 'render_tab_content' )
			)
		);
		$this->hub_tab_registry->register(
			new Tab(
				PairingController::TAB_ID,
				__( 'Support Chat pairing', 'universal-telegram' ),
				CapabilityRegistrar::MANAGE,
				array( $this->support_chat_pairing_controller, 'render_tab_content' )
			)
		);

		add_action( 'admin_menu', array( new LegacyUrlRedirector(), 'register' ) );

		// Generic operational Telegram alerts (ADR-0044 §1).
		$alert_evaluator = new AlertEvaluator(
			$intelligence_settings,
			$destination_eligibility,
			new EventCountAggregator( $this->schema_health ),
			$alert_repository,
			$this->message_dispatcher,
			$this->woocommerce_support
		);
		$alert_sweep     = new AlertSweep( $alert_evaluator, new IntelligenceStateRepository( $this->schema_health ) );
		add_action( AlertSweep::JOB_TYPE, array( $alert_sweep, 'run' ) );
		add_action( 'init', array( $alert_sweep, 'register' ) );

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
	 * The schema health service.
	 *
	 * @return SchemaHealth|null
	 */
	public function schema_health(): ?SchemaHealth {
		return $this->schema_health;
	}

	/**
	 * The audit logger service.
	 *
	 * @return AuditLogger|null
	 */
	public function audit_logger(): ?AuditLogger {
		return $this->audit_logger;
	}

	/**
	 * The audit log repository service.
	 *
	 * @return AuditLogRepository|null
	 */
	public function audit_log_repository(): ?AuditLogRepository {
		return $this->audit_log_repository;
	}

	/**
	 * The woocommerce support service.
	 *
	 * @return WooCommerceSupport|null
	 */
	public function woocommerce_support(): ?WooCommerceSupport {
		return $this->woocommerce_support;
	}

	/**
	 * The credential vault service.
	 *
	 * @return CredentialVault|null
	 */
	public function credential_vault(): ?CredentialVault {
		return $this->credential_vault;
	}

	/**
	 * The capability registrar service.
	 *
	 * @return CapabilityRegistrar|null
	 */
	public function capability_registrar(): ?CapabilityRegistrar {
		return $this->capability_registrar;
	}

	/**
	 * The handler registry service.
	 *
	 * @return HandlerRegistry|null
	 */
	public function handler_registry(): ?HandlerRegistry {
		return $this->handler_registry;
	}

	/**
	 * The dispatcher service.
	 *
	 * @return Dispatcher|null
	 */
	public function dispatcher(): ?Dispatcher {
		return $this->dispatcher;
	}

	/**
	 * The queue health service.
	 *
	 * @return QueueHealth|null
	 */
	public function queue_health(): ?QueueHealth {
		return $this->queue_health;
	}

	/**
	 * The worker runner service.
	 *
	 * @return WorkerRunner|null
	 */
	public function worker_runner(): ?WorkerRunner {
		return $this->worker_runner;
	}

	/**
	 * The diagnostics page service.
	 *
	 * @return DiagnosticsPage|null
	 */
	public function diagnostics_page(): ?DiagnosticsPage {
		return $this->diagnostics_page;
	}

	/**
	 * The hub tab registry service.
	 *
	 * @return TabRegistry|null
	 */
	public function hub_tab_registry(): ?TabRegistry {
		return $this->hub_tab_registry;
	}

	/**
	 * The hub page service.
	 *
	 * @return HubPage|null
	 */
	public function hub_page(): ?HubPage {
		return $this->hub_page;
	}

	/**
	 * The self test service.
	 *
	 * @return SelfTest|null
	 */
	public function self_test(): ?SelfTest {
		return $this->self_test;
	}

	/**
	 * The bot profile repository service.
	 *
	 * @return BotProfileRepository|null
	 */
	public function bot_profile_repository(): ?BotProfileRepository {
		return $this->bot_profile_repository;
	}

	/**
	 * The destination repository service.
	 *
	 * @return DestinationRepository|null
	 */
	public function destination_repository(): ?DestinationRepository {
		return $this->destination_repository;
	}

	/**
	 * The telegram api client service.
	 *
	 * @return TelegramApiClient|null
	 */
	public function telegram_api_client(): ?TelegramApiClient {
		return $this->telegram_api_client;
	}

	/**
	 * The outbound message repository service.
	 *
	 * @return OutboundMessageRepository|null
	 */
	public function outbound_message_repository(): ?OutboundMessageRepository {
		return $this->outbound_message_repository;
	}

	/**
	 * The message dispatcher service.
	 *
	 * @return MessageDispatcher|null
	 */
	public function message_dispatcher(): ?MessageDispatcher {
		return $this->message_dispatcher;
	}

	/**
	 * The update repository service.
	 *
	 * @return UpdateRepository|null
	 */
	public function update_repository(): ?UpdateRepository {
		return $this->update_repository;
	}

	/**
	 * The webhook secret verifier service.
	 *
	 * @return WebhookSecretVerifier|null
	 */
	public function webhook_secret_verifier(): ?WebhookSecretVerifier {
		return $this->webhook_secret_verifier;
	}

	/**
	 * The webhook controller service.
	 *
	 * @return WebhookController|null
	 */
	public function webhook_controller(): ?WebhookController {
		return $this->webhook_controller;
	}

	/**
	 * The bot command dispatcher service.
	 *
	 * @return BotCommandDispatcher|null
	 */
	public function bot_command_dispatcher(): ?BotCommandDispatcher {
		return $this->bot_command_dispatcher;
	}

	/**
	 * The rate limiter service.
	 *
	 * @return RateLimiter|null
	 */
	public function rate_limiter(): ?RateLimiter {
		return $this->rate_limiter;
	}

	/**
	 * The circuit breaker service.
	 *
	 * @return CircuitBreaker|null
	 */
	public function circuit_breaker(): ?CircuitBreaker {
		return $this->circuit_breaker;
	}

	/**
	 * The queue health alert service.
	 *
	 * @return QueueHealthAlert|null
	 */
	public function queue_health_alert(): ?QueueHealthAlert {
		return $this->queue_health_alert;
	}

	/**
	 * The retention cleanup handler service.
	 *
	 * @return RetentionCleanupHandler|null
	 */
	public function retention_cleanup_handler(): ?RetentionCleanupHandler {
		return $this->retention_cleanup_handler;
	}

	/**
	 * The webhook registration coordinator service.
	 *
	 * @return WebhookRegistrationCoordinator|null
	 */
	public function webhook_registration_coordinator(): ?WebhookRegistrationCoordinator {
		return $this->webhook_registration_coordinator;
	}

	/**
	 * The bot management page service.
	 *
	 * @return BotManagementPage|null
	 */
	public function bot_management_page(): ?BotManagementPage {
		return $this->bot_management_page;
	}

	/**
	 * The bot management controller service.
	 *
	 * @return BotManagementController|null
	 */
	public function bot_management_controller(): ?BotManagementController {
		return $this->bot_management_controller;
	}

	/**
	 * The event registry service.
	 *
	 * @return Registry|null
	 */
	public function event_registry(): ?Registry {
		return $this->event_registry;
	}

	/**
	 * The event dispatcher service.
	 *
	 * @return EventDispatcher|null
	 */
	public function event_dispatcher(): ?EventDispatcher {
		return $this->event_dispatcher;
	}

	/**
	 * The event emitter service.
	 *
	 * @return EventEmitter|null
	 */
	public function event_emitter(): ?EventEmitter {
		return $this->event_emitter;
	}

	/**
	 * The notification rule repository service.
	 *
	 * @return NotificationRuleRepository|null
	 */
	public function notification_rule_repository(): ?NotificationRuleRepository {
		return $this->notification_rule_repository;
	}

	/**
	 * The dispatch log repository service.
	 *
	 * @return DispatchLogRepository|null
	 */
	public function dispatch_log_repository(): ?DispatchLogRepository {
		return $this->dispatch_log_repository;
	}

	/**
	 * The event history repository service.
	 *
	 * @return EventHistoryRepository|null
	 */
	public function event_history_repository(): ?EventHistoryRepository {
		return $this->event_history_repository;
	}

	/**
	 * The event catalog page service.
	 *
	 * @return EventCatalogPage|null
	 */
	public function event_catalog_page(): ?EventCatalogPage {
		return $this->event_catalog_page;
	}

	/**
	 * The rule builder page service.
	 *
	 * @return RuleBuilderPage|null
	 */
	public function rule_builder_page(): ?RuleBuilderPage {
		return $this->rule_builder_page;
	}

	/**
	 * The rule builder request handler service.
	 *
	 * @return RuleBuilderRequestHandler|null
	 */
	public function rule_builder_request_handler(): ?RuleBuilderRequestHandler {
		return $this->rule_builder_request_handler;
	}

	/**
	 * The notification tester page service.
	 *
	 * @return NotificationTesterPage|null
	 */
	public function notification_tester_page(): ?NotificationTesterPage {
		return $this->notification_tester_page;
	}

	/**
	 * The event history page service.
	 *
	 * @return EventHistoryPage|null
	 */
	public function event_history_page(): ?EventHistoryPage {
		return $this->event_history_page;
	}
}
