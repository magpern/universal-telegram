<?php
/**
 * Diagnostics admin page.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Administration\Diagnostics;

use UniversalTelegram\Administration\Hub\HubPage;
use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Telegram\Reliability\QueueHealthAlert;

/**
 * The Diagnostics tab of the administration hub (ADR-0020), gated on the
 * universal_telegram_manage capability, re-verified inside
 * render_tab_content() as defense in depth alongside the Hub shell's own
 * capability check. Remains fully reachable while the schema is
 * degraded, rendering a notice using only the stable failure code. Extended
 * by M01, WP11, with a Telegram health section (via the already-extended
 * DiagnosticsReport) and a capability-gated, site-wide admin_notices
 * queue-health alert banner — never a Telegram message (docs/adr/0014), the
 * transport itself may be the thing that is failing. The banner's
 * underlying computation is cached in a 60-second transient so it does not
 * run its queries on every admin page load. SLUG is retained as this
 * screen's legacy compatibility-redirect slug (M04.1 plan §5), not as a
 * registered menu page of its own.
 */
final class DiagnosticsPage {

	public const SLUG = 'universal-telegram-diagnostics';

	private const ALERT_CACHE_KEY = QueueHealthAlert::ALERT_CACHE_TRANSIENT;

	/**
	 * Constructor.
	 *
	 * @param DiagnosticsReport       $report                             The report data source.
	 * @param SchemaHealth            $schema_health                       The current schema-availability state.
	 * @param SelfTest                $self_test                           The bounded diagnostic self-test.
	 * @param QueueHealthAlert        $queue_health_alert                  The alert computation for the site-wide banner.
	 * @param int                     $stale_pending_threshold_seconds      The message-staleness threshold, in seconds.
	 * @param int                     $stale_registration_threshold_hours   The registration-staleness threshold, in hours.
	 */
	public function __construct(
		private readonly DiagnosticsReport $report,
		private readonly SchemaHealth $schema_health,
		private readonly SelfTest $self_test,
		private readonly QueueHealthAlert $queue_health_alert,
		private readonly int $stale_pending_threshold_seconds = 1800,
		private readonly int $stale_registration_threshold_hours = 24
	) {}

	/**
	 * The admin_notices callback: a site-wide, capability-gated banner,
	 * shown whenever the Telegram queue-health alert condition is active.
	 * Never itself a Telegram message.
	 */
	public function render_admin_notice(): void {
		if ( ! current_user_can( CapabilityRegistrar::MANAGE ) ) {
			return;
		}

		if ( ! $this->is_alert_active_cached() ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s <a href="%s">%s</a></p></div>',
			esc_html__( 'Telegram Operations Hub: a delivery problem needs attention (dead-lettered messages, an open circuit breaker, a stalled send, or an unresolved webhook rotation).', 'universal-telegram' ),
			esc_url( admin_url( 'admin.php?page=' . HubPage::SLUG . '&tab=diagnostics' ) ),
			esc_html__( 'View diagnostics', 'universal-telegram' )
		);
	}

	/**
	 * The alert condition, cached for 60 seconds to bound its query cost
	 * across repeated admin page loads.
	 *
	 * @return bool
	 */
	private function is_alert_active_cached(): bool {
		if ( ! $this->schema_health->is_available() ) {
			return false;
		}

		$cached = get_transient( self::ALERT_CACHE_KEY );

		if ( false !== $cached ) {
			return (bool) $cached;
		}

		$active = $this->queue_health_alert->is_active( $this->stale_pending_threshold_seconds, $this->stale_registration_threshold_hours );
		set_transient( self::ALERT_CACHE_KEY, $active, 60 );

		return $active;
	}

	/**
	 * Renders this tab's content only (no outer .wrap/<h1> — owned by
	 * HubPage). The Hub's own capability check (HubPage::render()) already
	 * denies an unauthorized user before this ever runs; the explicit
	 * check here is defense in depth, unchanged from before the M04.1
	 * migration into the Hub shell.
	 */
	public function render_tab_content(): void {
		if ( ! current_user_can( CapabilityRegistrar::MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'universal-telegram' ) );
		}

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
	}

	/**
	 * Renders the report table and the recent-audit-entries table.
	 */
	private function render_report(): void {
		$data = $this->report->generate();

		echo '<table class="widefat striped"><tbody>';
		foreach ( $data as $key => $value ) {
			if ( 'recent_audit_entries' === $key
				|| str_starts_with( (string) $key, 'automations_' )
				|| str_starts_with( (string) $key, 'bot_commands_' ) ) {
				continue;
			}

			printf(
				'<tr><th scope="row">%s</th><td>%s</td></tr>',
				esc_html( (string) $key ),
				esc_html( is_bool( $value ) ? ( $value ? 'yes' : 'no' ) : (string) $value )
			);
		}
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Automations (M02)', 'universal-telegram' ) . '</h2>';
		echo '<table class="widefat striped"><tbody>';
		foreach ( $data as $key => $value ) {
			if ( ! str_starts_with( (string) $key, 'automations_' ) ) {
				continue;
			}

			printf(
				'<tr><th scope="row">%s</th><td>%s</td></tr>',
				esc_html( (string) $key ),
				esc_html( is_bool( $value ) ? ( $value ? 'yes' : 'no' ) : (string) $value )
			);
		}
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Bot commands (M08)', 'universal-telegram' ) . '</h2>';
		echo '<table class="widefat striped"><tbody>';
		foreach ( $data as $key => $value ) {
			if ( ! str_starts_with( (string) $key, 'bot_commands_' ) ) {
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
