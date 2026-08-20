<?php
/**
 * Composition root.
 *
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Core;

use UniversalTelegram\Core\Configuration\Settings;

/**
 * Singleton composition root. Constructs and wires every M00 service by
 * hand inside init(); no dependency-injection container.
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
	}
}
