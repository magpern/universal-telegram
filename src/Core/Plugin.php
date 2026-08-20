<?php
/**
 * Composition root.
 *
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Core;

use UniversalTelegram\Core\Configuration\Settings;
use UniversalTelegram\Persistence\MigrationFailedException;
use UniversalTelegram\Persistence\MigrationLock;
use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Persistence\SchemaHealth;

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
	}

	/**
	 * The current request's schema-availability state. Available only
	 * after init() has run.
	 */
	public function schema_health(): ?SchemaHealth {
		return $this->schema_health;
	}
}
