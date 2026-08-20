<?php
/**
 * Composition root.
 *
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Core;

use UniversalTelegram\Audit\AuditLogger;
use UniversalTelegram\Audit\AuditLogRepository;
use UniversalTelegram\Core\Configuration\Settings;
use UniversalTelegram\Integrations\WooCommerce\WooCommerceSupport;
use UniversalTelegram\Persistence\MigrationFailedException;
use UniversalTelegram\Persistence\MigrationLock;
use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Privacy\Redactor;

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
}
