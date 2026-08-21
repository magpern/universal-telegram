<?php
/**
 * Event catalog admin page.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Administration\Automations;

use UniversalTelegram\Administration\Diagnostics\DiagnosticsPage;
use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;
use UniversalTelegram\Events\Registry;

/**
 * Read-only listing of every registered event type, its schema version,
 * and its declared allowlisted fields (M02 plan §9.1). Gated on
 * MANAGE_AUTOMATIONS via add_submenu_page()'s own capability parameter,
 * with an explicit re-check inside render() as defense in depth, exactly
 * mirroring DiagnosticsPage's existing pattern.
 */
final class EventCatalogPage {

	public const SLUG = 'universal-telegram-events';

	/**
	 * Constructor.
	 *
	 * @param Registry $registry The current request's event registry.
	 */
	public function __construct( private readonly Registry $registry ) {}

	/**
	 * Registers the admin menu entry.
	 */
	public function register_menu(): void {
		add_submenu_page(
			DiagnosticsPage::SLUG,
			__( 'Event Catalog', 'universal-telegram' ),
			__( 'Events', 'universal-telegram' ),
			CapabilityRegistrar::MANAGE_AUTOMATIONS,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Renders the page.
	 */
	public function render(): void {
		if ( ! current_user_can( CapabilityRegistrar::MANAGE_AUTOMATIONS ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'universal-telegram' ) );
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Event Catalog', 'universal-telegram' ) . '</h1>';
		echo '<table class="widefat striped"><thead><tr><th>' .
			esc_html__( 'Event type', 'universal-telegram' ) . '</th><th>' .
			esc_html__( 'Schema version', 'universal-telegram' ) . '</th><th>' .
			esc_html__( 'Allowed fields (conditions/templates)', 'universal-telegram' ) . '</th><th>' .
			esc_html__( 'History-visible fields', 'universal-telegram' ) . '</th></tr></thead><tbody>';

		foreach ( $this->registry->all() as $entry ) {
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( $entry['event_type'] ),
				esc_html( (string) $entry['schema_version'] ),
				esc_html( implode( ', ', $entry['allowed_variable_fields'] ) ),
				esc_html( implode( ', ', $entry['history_projection_fields'] ) )
			);
		}

		echo '</tbody></table>';
		echo '</div>';
	}
}
