<?php
/**
 * Composition root.
 *
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Core;

use UniversalTelegram\Administration\Diagnostics\DiagnosticsPage;
use UniversalTelegram\Administration\Diagnostics\DiagnosticsReport;
use UniversalTelegram\Administration\Diagnostics\SelfTest;
use UniversalTelegram\Audit\AuditLogger;
use UniversalTelegram\Audit\AuditLogRepository;
use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;
use UniversalTelegram\Core\Configuration\Settings;
use UniversalTelegram\Core\Security\CredentialVault;
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
		$this->queue_health_alert          = new QueueHealthAlert( $this->outbound_message_repository, $this->circuit_breaker );

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

		$report                 = new DiagnosticsReport(
			$this->queue_health,
			$this->audit_log_repository,
			$this->woocommerce_support,
			$this->schema_health
		);
		$this->diagnostics_page = new DiagnosticsPage( $report, $this->schema_health, $this->self_test );
		add_action( 'admin_menu', array( $this->diagnostics_page, 'register_menu' ) );
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
}
