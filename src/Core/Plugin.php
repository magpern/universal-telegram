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

		$settings = new Settings();
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
}
