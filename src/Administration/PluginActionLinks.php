<?php
/**
 * Plugins-list row action links.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Administration;

use UniversalTelegram\Administration\Diagnostics\DiagnosticsPage;
use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;

/**
 * Adds the standard WordPress plugin-row "Settings" action link on the
 * Plugins screen, pointing to the plugin's existing canonical
 * administration landing page (Diagnostics — the single top-level menu
 * entry every other admin screen, including the M02 Automations pages, is
 * already a submenu of). No duplicate settings page is created. Visible
 * only to a user holding the plugin's general management capability.
 */
final class PluginActionLinks {

	/**
	 * Constructor.
	 *
	 * @param string $plugin_basename The plugin's own basename, e.g. "universal-telegram/universal-telegram.php".
	 */
	public function __construct( private readonly string $plugin_basename ) {}

	/**
	 * Registers the filter.
	 */
	public function register(): void {
		add_filter( 'plugin_action_links_' . $this->plugin_basename, array( $this, 'add_settings_link' ) );
	}

	/**
	 * The plugin_action_links_{basename} filter callback.
	 *
	 * @param array<int, string> $links The existing row action links.
	 *
	 * @return array<int, string>
	 */
	public function add_settings_link( array $links ): array {
		if ( ! current_user_can( CapabilityRegistrar::MANAGE ) ) {
			return $links;
		}

		$url = admin_url( 'admin.php?page=' . DiagnosticsPage::SLUG );

		array_unshift(
			$links,
			sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html__( 'Settings', 'universal-telegram' ) )
		);

		return $links;
	}
}
