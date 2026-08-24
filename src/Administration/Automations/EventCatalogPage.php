<?php
/**
 * Event catalog admin page.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Administration\Automations;

use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;
use UniversalTelegram\Events\Registry;

/**
 * Read-only listing of every registered event type, its schema version,
 * and its declared allowlisted fields (M02 plan §9.1). The Events tab of
 * the administration hub (ADR-0020), gated on MANAGE_AUTOMATIONS,
 * re-verified inside render_tab_content() as defense in depth alongside
 * the Hub shell's own capability check.
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
	 * Renders this tab's content only (no outer .wrap/<h1> — owned by
	 * HubPage).
	 */
	public function render_tab_content(): void {
		if ( ! current_user_can( CapabilityRegistrar::MANAGE_AUTOMATIONS ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'universal-telegram' ) );
		}

		echo '<p>' . esc_html__(
			'Every row below is one event this plugin can detect. Use the description when choosing what to monitor; the technical event type is what rules and history filters use internally.',
			'universal-telegram'
		) . '</p>';

		echo '<table class="widefat striped"><thead><tr>';
		$this->render_column_header(
			__( 'Description', 'universal-telegram' ),
			__( 'Plain-language name for this event.', 'universal-telegram' )
		);
		$this->render_column_header(
			__( 'Event type', 'universal-telegram' ),
			__( 'Technical identifier used in notification rules, simulators, and event history filters.', 'universal-telegram' )
		);
		$this->render_column_header(
			__( 'Schema version', 'universal-telegram' ),
			__( 'Version of this event\'s field layout. Rules must match this version.', 'universal-telegram' )
		);
		$this->render_column_header(
			__( 'Allowed fields', 'universal-telegram' ),
			__( 'Data you may reference in rule conditions and message templates.', 'universal-telegram' )
		);
		$this->render_column_header(
			__( 'History-visible fields', 'universal-telegram' ),
			__( 'Non-sensitive fields stored in the event history log. Other fields exist at runtime but are withheld from history for privacy.', 'universal-telegram' )
		);
		echo '</tr></thead><tbody>';

		$entries = $this->registry->all();
		usort(
			$entries,
			static function ( array $left, array $right ): int {
				return strcasecmp(
					EventCatalogLabels::event_type_label( $left['event_type'] ),
					EventCatalogLabels::event_type_label( $right['event_type'] )
				);
			}
		);

		foreach ( $entries as $entry ) {
			echo '<tr>';
			printf(
				'<td>%s</td><td><code>%s</code></td><td>%s</td><td>%s</td><td>%s</td>',
				esc_html(
					__(
						EventCatalogLabels::event_type_label( $entry['event_type'] ),
						'universal-telegram'
					)
				),
				esc_html( $entry['event_type'] ),
				esc_html( (string) $entry['schema_version'] ),
				$this->render_field_list( $entry['allowed_variable_fields'] ),
				$this->render_field_list( $entry['history_projection_fields'] )
			);
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Renders one table header cell with a hover title for its meaning.
	 *
	 * @param string $label       The visible column title.
	 * @param string $description Longer help shown on hover.
	 */
	private function render_column_header( string $label, string $description ): void {
		printf(
			'<th scope="col" title="%s">%s</th>',
			esc_attr( $description ),
			esc_html( $label )
		);
	}

	/**
	 * Renders a list of schema fields with admin labels and technical paths.
	 *
	 * @param array<int, string> $fields Field dot-paths.
	 *
	 * @return string Safe HTML for one table cell.
	 */
	private function render_field_list( array $fields ): string {
		if ( array() === $fields ) {
			return esc_html__( 'None', 'universal-telegram' );
		}

		$items = array();

		foreach ( $fields as $field ) {
			$items[] = sprintf(
				'<li>%s <code>%s</code></li>',
				esc_html( __( EventCatalogLabels::field_label( $field ), 'universal-telegram' ) ),
				esc_html( $field )
			);
		}

		return '<ul style="margin:0;">' . implode( '', $items ) . '</ul>';
	}
}
