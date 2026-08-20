<?php
/**
 * Rule simulation admin page.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Administration\Automations;

use UniversalTelegram\Administration\Diagnostics\DiagnosticsPage;
use UniversalTelegram\Automations\RuleSimulator;
use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;
use UniversalTelegram\Events\Registry;

/**
 * A capability-gated tool to preview rule evaluation against hand-entered
 * sample data without any live external traffic (M02 plan §9.2). May
 * display INTERNAL field values transiently, in-memory, to the viewing
 * administrator — this is a live render only, never a write to any table,
 * and is explicitly distinguished from the PUBLIC-only event-history
 * browser.
 */
final class RuleSimulatorPage {

	public const SLUG = 'universal-telegram-rule-simulator';

	/**
	 * Constructor.
	 *
	 * @param RuleSimulator $simulator The simulation engine.
	 * @param Registry       $registry  The current request's event registry.
	 */
	public function __construct(
		private readonly RuleSimulator $simulator,
		private readonly Registry $registry
	) {}

	/**
	 * Registers the admin menu entry.
	 */
	public function register_menu(): void {
		add_submenu_page(
			DiagnosticsPage::SLUG,
			__( 'Rule Simulator', 'universal-telegram' ),
			__( 'Simulator', 'universal-telegram' ),
			CapabilityRegistrar::MANAGE_AUTOMATIONS,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Renders the page, including a same-request simulation if a sample
	 * was submitted (a GET-only preview tool; no state-changing write of
	 * any kind occurs, so no nonce is required for this read-only action).
	 */
	public function render(): void {
		if ( ! current_user_can( CapabilityRegistrar::MANAGE_AUTOMATIONS ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'universal-telegram' ) );
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Rule Simulator', 'universal-telegram' ) . '</h1>';
		echo '<p>' . esc_html__( 'Preview which rules would match a sample event. No Telegram message is ever sent, and no dispatch-log row is ever written by this tool.', 'universal-telegram' ) . '</p>';

		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '" />';

		echo '<table class="form-table"><tbody>';
		echo '<tr><th><label for="ut-sim-event-type">' . esc_html__( 'Event type', 'universal-telegram' ) . '</label></th><td><select id="ut-sim-event-type" name="event_type">';
		foreach ( $this->registry->all() as $entry ) {
			printf( '<option value="%s">%s</option>', esc_attr( $entry['event_type'] ), esc_html( $entry['event_type'] ) );
		}
		echo '</select></td></tr>';
		echo '<tr><th><label for="ut-sim-data">' . esc_html__( 'Sample data (JSON: actor/subject/context/payload)', 'universal-telegram' ) . '</label></th><td><textarea id="ut-sim-data" name="sample_data" class="large-text code" rows="4">{}</textarea></td></tr>';
		echo '</tbody></table>';

		submit_button( __( 'Simulate', 'universal-telegram' ) );
		echo '</form>';

		if ( isset( $_GET['event_type'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only GET preview, no state changed.
			$this->render_result();
		}

		echo '</div>';
	}

	/**
	 * Runs and renders one simulation.
	 */
	private function render_result(): void {
		$event_type  = isset( $_GET['event_type'] ) ? sanitize_text_field( wp_unslash( $_GET['event_type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$sample_json = isset( $_GET['sample_data'] ) ? wp_unslash( $_GET['sample_data'] ) : '{}'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$sample_data = json_decode( (string) $sample_json, true );

		if ( ! is_array( $sample_data ) ) {
			$sample_data = array();
		}

		$result = $this->simulator->simulate( $event_type, $sample_data, 'simulation-' . wp_generate_uuid4() );

		echo '<h2>' . esc_html__( 'Result', 'universal-telegram' ) . '</h2>';

		if ( null !== $result->error_code() ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $result->error_code() ) );
			return;
		}

		echo '<table class="widefat striped"><thead><tr><th>' .
			esc_html__( 'Rule', 'universal-telegram' ) . '</th><th>' .
			esc_html__( 'Outcome', 'universal-telegram' ) . '</th><th>' .
			esc_html__( 'Reason', 'universal-telegram' ) . '</th></tr></thead><tbody>';

		foreach ( $result->entries() as $entry ) {
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( $entry['rule_name'] ),
				esc_html( $entry['outcome'] ),
				esc_html( (string) ( $entry['reason_code'] ?? '' ) )
			);
		}

		echo '</tbody></table>';
	}
}
