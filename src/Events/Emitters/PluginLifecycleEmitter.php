<?php
/**
 * Plugin activation/deactivation event emission.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Events\Emitters;

use UniversalTelegram\Events\Registry;
use UniversalTelegram\Privacy\Classification;

/**
 * Thin, reviewed callbacks on activated_plugin and deactivated_plugin.
 * Neither is deduplicable — a later re-activation/deactivation of the same
 * plugin is a genuinely new occurrence (M02 plan §8).
 */
final class PluginLifecycleEmitter {

	public const PLUGIN_ACTIVATED   = 'wordpress.plugin_activated';
	public const PLUGIN_DEACTIVATED = 'wordpress.plugin_deactivated';

	/**
	 * Registers this emitter's event types.
	 *
	 * @param Registry $registry The current request's event registry.
	 */
	public function register_event_types( Registry $registry ): void {
		$fields = array(
			'payload.plugin'       => Classification::PUBLIC,
			'payload.network_wide' => Classification::PUBLIC,
		);

		$registry->register( self::PLUGIN_ACTIVATED, 1, $fields, array( 'payload.plugin', 'payload.network_wide' ), array( 'payload.plugin', 'payload.network_wide' ) );
		$registry->register( self::PLUGIN_DEACTIVATED, 1, $fields, array( 'payload.plugin', 'payload.network_wide' ), array( 'payload.plugin', 'payload.network_wide' ) );
	}

	/**
	 * The activated_plugin callback.
	 *
	 * @param string $plugin       The plugin's basename.
	 * @param bool   $network_wide Whether activated network-wide.
	 */
	public function on_activated( string $plugin, bool $network_wide ): void {
		universal_telegram_emit_event(
			self::PLUGIN_ACTIVATED,
			array( 'payload' => array( 'plugin' => $plugin, 'network_wide' => $network_wide ) ),
			wp_generate_uuid4()
		);
	}

	/**
	 * The deactivated_plugin callback.
	 *
	 * @param string $plugin       The plugin's basename.
	 * @param bool   $network_wide Whether deactivated network-wide.
	 */
	public function on_deactivated( string $plugin, bool $network_wide ): void {
		universal_telegram_emit_event(
			self::PLUGIN_DEACTIVATED,
			array( 'payload' => array( 'plugin' => $plugin, 'network_wide' => $network_wide ) ),
			wp_generate_uuid4()
		);
	}
}
