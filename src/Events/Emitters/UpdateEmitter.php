<?php
/**
 * Available/completed WordPress core, plugin, and theme update emission.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Events\Emitters;

use UniversalTelegram\Events\Registry;
use UniversalTelegram\Privacy\Classification;

/**
 * wordpress.update_available is checked daily against the site's own
 * update transients, deduplicated per component/version/day.
 * wordpress.update_completed fires on upgrader_process_complete,
 * deduplicated per upgrade action's own signature (M02 plan §8).
 */
final class UpdateEmitter {

	public const UPDATE_AVAILABLE = 'wordpress.update_available';
	public const UPDATE_COMPLETED = 'wordpress.update_completed';

	public const CHECK_HOOK = 'universal_telegram_check_updates';

	/**
	 * Registers this emitter's event types.
	 *
	 * @param Registry $registry The current request's event registry.
	 */
	public function register_event_types( Registry $registry ): void {
		$registry->register(
			self::UPDATE_AVAILABLE,
			1,
			array(
				'payload.component'  => Classification::PUBLIC,
				'payload.new_version' => Classification::PUBLIC,
			),
			array( 'payload.component', 'payload.new_version' ),
			array( 'payload.component', 'payload.new_version' )
		);

		$registry->register(
			self::UPDATE_COMPLETED,
			1,
			array(
				'payload.type'   => Classification::PUBLIC,
				'payload.action' => Classification::PUBLIC,
			),
			array( 'payload.type', 'payload.action' ),
			array( 'payload.type', 'payload.action' )
		);
	}

	/**
	 * The daily recurring check against the site's own update transients.
	 */
	public function check_for_updates(): void {
		$this->check_core_updates();
		$this->check_plugin_updates();
		$this->check_theme_updates();
	}

	/**
	 * Emits one event per pending core update.
	 */
	private function check_core_updates(): void {
		$core = get_site_transient( 'update_core' );

		if ( ! isset( $core->updates ) || ! is_array( $core->updates ) ) {
			return;
		}

		foreach ( $core->updates as $update ) {
			if ( ! isset( $update->response ) || 'upgrade' !== $update->response || ! isset( $update->version ) ) {
				continue;
			}

			$this->emit_update_available( 'core', (string) $update->version );
		}
	}

	/**
	 * Emits one event per pending plugin update.
	 */
	private function check_plugin_updates(): void {
		$plugins = get_site_transient( 'update_plugins' );

		if ( ! isset( $plugins->response ) || ! is_array( $plugins->response ) ) {
			return;
		}

		foreach ( $plugins->response as $plugin_file => $data ) {
			if ( ! isset( $data->new_version ) ) {
				continue;
			}

			$this->emit_update_available( 'plugin:' . (string) $plugin_file, (string) $data->new_version );
		}
	}

	/**
	 * Emits one event per pending theme update.
	 */
	private function check_theme_updates(): void {
		$themes = get_site_transient( 'update_themes' );

		if ( ! isset( $themes->response ) || ! is_array( $themes->response ) ) {
			return;
		}

		foreach ( $themes->response as $stylesheet => $data ) {
			if ( ! isset( $data['new_version'] ) ) {
				continue;
			}

			$this->emit_update_available( 'theme:' . (string) $stylesheet, (string) $data['new_version'] );
		}
	}

	/**
	 * Emits one wordpress.update_available occurrence, deduplicated per
	 * component/version/calendar day.
	 *
	 * @param string $component  The updatable component's stable identifier.
	 * @param string $new_version The version available.
	 */
	private function emit_update_available( string $component, string $new_version ): void {
		$day = gmdate( 'Y-m-d' );

		universal_telegram_emit_event(
			self::UPDATE_AVAILABLE,
			array( 'payload' => array( 'component' => $component, 'new_version' => $new_version ) ),
			hash( 'sha256', "update_available:{$component}:{$new_version}:{$day}" )
		);
	}

	/**
	 * The upgrader_process_complete callback.
	 *
	 * @param mixed                $upgrader   The upgrader instance. Not read.
	 * @param array<string, mixed> $hook_extra WordPress core's own upgrade-context array.
	 */
	public function on_update_completed( $upgrader, array $hook_extra ): void {
		$type   = isset( $hook_extra['type'] ) ? (string) $hook_extra['type'] : 'unknown';
		$action = isset( $hook_extra['action'] ) ? (string) $hook_extra['action'] : 'unknown';

		$signature_source = array(
			'type'    => $type,
			'action'  => $action,
			'plugins' => $hook_extra['plugins'] ?? null,
			'plugin'  => $hook_extra['plugin'] ?? null,
			'themes'  => $hook_extra['themes'] ?? null,
			'theme'   => $hook_extra['theme'] ?? null,
		);

		universal_telegram_emit_event(
			self::UPDATE_COMPLETED,
			array( 'payload' => array( 'type' => $type, 'action' => $action ) ),
			hash( 'sha256', 'update_completed:' . wp_json_encode( $signature_source ) )
		);
	}
}
