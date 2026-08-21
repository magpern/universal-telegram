<?php
/**
 * Event history browser admin page.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Administration\Automations;

use UniversalTelegram\Administration\Diagnostics\DiagnosticsPage;
use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;
use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Persistence\SchemaHealth;

/**
 * Read-only admin screen over event_history rows — event type, schema
 * version, occurred_at, source, and the already-PUBLIC-only
 * projected_fields_json (M02 plan §9.3). Renders only what is already
 * stored; never an INTERNAL field, since none can be present in this table
 * by construction (docs/adr/0017).
 */
final class EventHistoryPage {

	public const SLUG = 'universal-telegram-event-history';

	private const PER_PAGE = 20;

	/**
	 * Constructor.
	 *
	 * @param SchemaHealth $schema_health The current schema-availability state.
	 */
	public function __construct( private readonly SchemaHealth $schema_health ) {}

	/**
	 * Registers the admin menu entry.
	 */
	public function register_menu(): void {
		add_submenu_page(
			DiagnosticsPage::SLUG,
			__( 'Event History', 'universal-telegram' ),
			__( 'Event History', 'universal-telegram' ),
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
		echo '<h1>' . esc_html__( 'Event History', 'universal-telegram' ) . '</h1>';

		if ( ! $this->schema_health->is_available() ) {
			$failure_code = $this->schema_health->failure_code();
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: a stable, fixed failure code, never a raw database error */
						__( 'Schema unavailable (%s).', 'universal-telegram' ),
						null !== $failure_code ? $failure_code->value : 'unknown'
					)
				)
			);
			echo '</div>';
			return;
		}

		$this->render_rows();
		echo '</div>';
	}

	/**
	 * Renders the paginated, filterable event-history table.
	 */
	private function render_rows(): void {
		global $wpdb;

		$page       = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination.
		$event_type = isset( $_GET['event_type'] ) ? sanitize_text_field( wp_unslash( $_GET['event_type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$offset     = ( $page - 1 ) * self::PER_PAGE;
		$table      = $wpdb->prefix . Migrator::EVENT_HISTORY_TABLE;

		if ( '' !== $event_type ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE event_type = %s ORDER BY occurred_at DESC LIMIT %d OFFSET %d", $event_type, self::PER_PAGE, $offset ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table} ORDER BY occurred_at DESC LIMIT %d OFFSET %d", self::PER_PAGE, $offset ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				ARRAY_A
			);
		}

		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '" />';
		echo '<input type="text" name="event_type" value="' . esc_attr( $event_type ) . '" placeholder="' . esc_attr__( 'Filter by event type', 'universal-telegram' ) . '" />';
		submit_button( __( 'Filter', 'universal-telegram' ), '', '', false );
		echo '</form>';

		echo '<table class="widefat striped"><thead><tr><th>' .
			esc_html__( 'Event type', 'universal-telegram' ) . '</th><th>' .
			esc_html__( 'Schema version', 'universal-telegram' ) . '</th><th>' .
			esc_html__( 'Occurred at', 'universal-telegram' ) . '</th><th>' .
			esc_html__( 'Source', 'universal-telegram' ) . '</th><th>' .
			esc_html__( 'Fields (PUBLIC only)', 'universal-telegram' ) . '</th></tr></thead><tbody>';

		foreach ( null === $rows ? array() : $rows as $row ) {
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( (string) $row['event_type'] ),
				esc_html( (string) $row['schema_version'] ),
				esc_html( (string) $row['occurred_at'] ),
				esc_html( (string) $row['source'] ),
				esc_html( (string) $row['projected_fields_json'] )
			);
		}

		echo '</tbody></table>';
	}
}
