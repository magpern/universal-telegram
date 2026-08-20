<?php
/**
 * Diagnostics admin page.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Administration\Diagnostics;

use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;
use UniversalTelegram\Persistence\SchemaHealth;

/**
 * The plugin's only administration screen at M00, gated on the
 * universal_telegram_manage capability through add_menu_page()'s own
 * capability parameter. Remains fully reachable while the schema is
 * degraded, rendering a notice using only the stable failure code.
 */
final class DiagnosticsPage {

	public const SLUG = 'universal-telegram-diagnostics';

	/**
	 * The report data source.
	 *
	 * @var DiagnosticsReport
	 */
	private DiagnosticsReport $report;

	/**
	 * The current schema-availability state.
	 *
	 * @var SchemaHealth
	 */
	private SchemaHealth $schema_health;

	/**
	 * The bounded diagnostic self-test.
	 *
	 * @var SelfTest
	 */
	private SelfTest $self_test;

	/**
	 * Constructor.
	 *
	 * @param DiagnosticsReport $report        The report data source.
	 * @param SchemaHealth      $schema_health The current schema-availability state.
	 * @param SelfTest          $self_test     The bounded diagnostic self-test.
	 */
	public function __construct( DiagnosticsReport $report, SchemaHealth $schema_health, SelfTest $self_test ) {
		$this->report        = $report;
		$this->schema_health = $schema_health;
		$this->self_test     = $self_test;
	}

	/**
	 * Registers the admin menu entry.
	 */
	public function register_menu(): void {
		add_menu_page(
			__( 'Telegram Operations Hub', 'universal-telegram' ),
			__( 'Telegram Hub', 'universal-telegram' ),
			CapabilityRegistrar::MANAGE,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Renders the page. WordPress core's own add_menu_page() capability
	 * parameter already denies an unauthorized user before this ever
	 * runs; the explicit check here is defense in depth.
	 */
	public function render(): void {
		if ( ! current_user_can( CapabilityRegistrar::MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'universal-telegram' ) );
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Telegram Operations Hub — Diagnostics', 'universal-telegram' ) . '</h1>';

		if ( ! $this->schema_health->is_available() ) {
			$failure_code = $this->schema_health->failure_code();
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: a stable, fixed failure code, never a raw database error */
						__( 'Schema unavailable (%s). This will be retried automatically.', 'universal-telegram' ),
						null !== $failure_code ? $failure_code->value : 'unknown'
					)
				)
			);
		}

		$this->render_report();
		$this->self_test->render_control();

		echo '</div>';
	}

	/**
	 * Renders the report table and the recent-audit-entries table.
	 */
	private function render_report(): void {
		$data = $this->report->generate();

		echo '<table class="widefat striped"><tbody>';
		foreach ( $data as $key => $value ) {
			if ( 'recent_audit_entries' === $key ) {
				continue;
			}

			printf(
				'<tr><th scope="row">%s</th><td>%s</td></tr>',
				esc_html( (string) $key ),
				esc_html( is_bool( $value ) ? ( $value ? 'yes' : 'no' ) : (string) $value )
			);
		}
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Recent audit entries', 'universal-telegram' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr><th>' .
			esc_html__( 'Time', 'universal-telegram' ) . '</th><th>' .
			esc_html__( 'Action', 'universal-telegram' ) . '</th><th>' .
			esc_html__( 'Context', 'universal-telegram' ) . '</th></tr></thead><tbody>';

		foreach ( $data['recent_audit_entries'] as $entry ) {
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( (string) ( $entry['occurred_at'] ?? '' ) ),
				esc_html( (string) ( $entry['action'] ?? '' ) ),
				esc_html( (string) ( $entry['context'] ?? '' ) )
			);
		}
		echo '</tbody></table>';
	}
}
