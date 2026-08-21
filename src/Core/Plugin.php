<?php
/**
 * Composition root.
 *
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Core;

use UniversalTelegram\Administration\Automations\EventCatalogPage;
use UniversalTelegram\Administration\Automations\EventHistoryPage;
use UniversalTelegram\Administration\Automations\RuleBuilderPage;
use UniversalTelegram\Administration\Automations\RuleBuilderRequestHandler;
use UniversalTelegram\Administration\Automations\RuleSimulatorPage;
use UniversalTelegram\Administration\Diagnostics\DiagnosticsPage;
use UniversalTelegram\Administration\Diagnostics\DiagnosticsReport;
use UniversalTelegram\Administration\Diagnostics\SelfTest;
use UniversalTelegram\Administration\PluginActionLinks;
use UniversalTelegram\Administration\Telegram\BotManagementController;
use UniversalTelegram\Administration\Telegram\BotManagementPage;
use UniversalTelegram\Administration\Visitor\VisitorTrackingPage;
use UniversalTelegram\Audit\AuditLogger;
use UniversalTelegram\Audit\AuditLogRepository;
use UniversalTelegram\Automations\DispatchLogRepository;
use UniversalTelegram\Automations\NotificationDispatcher;
use UniversalTelegram\Automations\NotificationRuleRepository;
use UniversalTelegram\Automations\RuleEvaluator;
use UniversalTelegram\Automations\RuleSimulator;
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
use UniversalTelegram\Integrations\WooCommerce\WooCommerceSupport;
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
	 * The per-bot/per-destination rate limiter, constructed by init().
	 *
	 * @var RateLimiter|null
	 */
	private ?RateLimiter $rate_limiter = null;

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

		$this->update_repository       = new UpdateRepository( $this->schema_health );
		$this->webhook_secret_verifier = new WebhookSecretVerifier( $this->bot_profile_repository, $this->audit_logger );
		$this->webhook_controller      = new WebhookController(
			$this->schema_health,
			$this->bot_profile_repository,
			$this->webhook_secret_verifier,
			$this->update_repository,
			(int) $settings_values['telegram_webhook_max_body_bytes']
		);
		add_action( 'rest_api_init', array( $this->webhook_controller, 'register_routes' ) );

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

		$this->webhook_registration_coordinator = new WebhookRegistrationCoordinator(
			$this->bot_profile_repository,
			$this->telegram_api_client,
			$this->audit_logger,
			rest_url( 'universal-telegram/v1/webhook/' )
		);

		$this->bot_management_controller = new BotManagementController(
			$this->bot_profile_repository,
			$this->destination_repository,
			$this->outbound_message_repository,
			$this->telegram_api_client,
			$this->webhook_registration_coordinator,
			$this->message_dispatcher,
			$this->dispatcher
		);
		add_action( 'admin_post_' . BotManagementController::ADMIN_POST_ACTION, array( $this->bot_management_controller, 'handle_request' ) );

		$this->bot_management_page = new BotManagementPage(
			$this->bot_profile_repository,
			$this->destination_repository,
			$this->update_repository,
			$this->outbound_message_repository
		);
		add_action( 'admin_menu', array( $this->bot_management_page, 'register_menu' ) );

		// Events/Automations (M02) repositories: constructed here, ahead of
		// DiagnosticsReport below (which reads their aggregate counts),
		// always unconditionally regardless of schema availability —
		// individual repositories check SchemaHealth at their own point of
		// use (docs/adr/0007).
		$this->event_registry               = new Registry();
		$this->event_history_repository     = new EventHistoryRepository( $this->schema_health, $this->event_registry, new Redactor() );
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
			(int) $settings_values['telegram_webhook_rotation_max_pending_hours']
		);
		add_action( 'admin_menu', array( $this->diagnostics_page, 'register_menu' ) );
		add_action( 'admin_notices', array( $this->diagnostics_page, 'render_admin_notice' ) );

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

		// Administration (M02): capability-gated event catalog and rule
		// builder screens, submenus of the existing top-level Diagnostics
		// page, mirroring how M01 added its own Telegram subdomain.
		$this->event_catalog_page = new EventCatalogPage( $this->event_registry );
		add_action( 'admin_menu', array( $this->event_catalog_page, 'register_menu' ) );

		$this->rule_builder_page = new RuleBuilderPage(
			$this->notification_rule_repository,
			$this->event_registry,
			$this->bot_profile_repository,
			$this->destination_repository
		);
		add_action( 'admin_menu', array( $this->rule_builder_page, 'register_menu' ) );

		$this->rule_builder_request_handler = new RuleBuilderRequestHandler( $this->notification_rule_repository );
		add_action( 'admin_post_' . RuleBuilderRequestHandler::ADMIN_POST_ACTION, array( $this->rule_builder_request_handler, 'handle_request' ) );

		if ( defined( 'UNIVERSAL_TELEGRAM_PLUGIN_FILE' ) ) {
			( new PluginActionLinks( plugin_basename( UNIVERSAL_TELEGRAM_PLUGIN_FILE ) ) )->register();
		}

		$rule_simulator = new RuleSimulator( $this->notification_rule_repository, $this->event_registry, $this->dispatch_log_repository, $notification_dispatcher );

		$this->rule_simulator_page = new RuleSimulatorPage( $rule_simulator, $this->event_registry );
		add_action( 'admin_menu', array( $this->rule_simulator_page, 'register_menu' ) );

		$this->event_history_page = new EventHistoryPage( $this->schema_health );
		add_action( 'admin_menu', array( $this->event_history_page, 'register_menu' ) );

		$this->visitor_tracking_page = new VisitorTrackingPage( $settings );
		add_action( 'admin_menu', array( $this->visitor_tracking_page, 'register_menu' ) );
		add_action( 'admin_post_' . VisitorTrackingPage::ADMIN_POST_ACTION, array( $this->visitor_tracking_page, 'handle_request' ) );

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
	 * The per-bot/per-destination rate limiter. Available only after init() has run.
	 */
	public function rate_limiter(): ?RateLimiter {
		return $this->rate_limiter;
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
