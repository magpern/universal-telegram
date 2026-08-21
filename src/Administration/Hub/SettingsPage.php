<?php
/**
 * Plugin-wide settings admin page.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Administration\Hub;

use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;
use UniversalTelegram\Core\Configuration\Settings;

/**
 * The Settings tab of the administration hub (ADR-0020, M04.1 plan §6):
 * exposes the plugin-wide Core\Configuration\Settings fields that had no
 * admin UI before this milestone (uninstall data removal and the
 * Telegram/event/dispatch/fatal-marker retention numbers) — existing,
 * already-sanitized fields, no new option or schema. visitor_* fields
 * stay on their own dedicated Visitor Tracking tab; Bot configuration
 * stays on its own dedicated Bots tab. Gated on the existing
 * CapabilityRegistrar::MANAGE capability — no new capability constant.
 * Every action independently re-verifies both the capability and its own
 * nonce, mirroring VisitorTrackingPage's exact existing pattern.
 *
 * Not declared final: tests override redirect_and_exit() to avoid a real
 * exit call terminating the test process, matching VisitorTrackingPage's
 * exact existing precedent.
 */
class SettingsPage {

	public const TAB_ID            = 'settings';
	public const ADMIN_POST_ACTION = 'universal_telegram_settings_save';
	public const NONCE_ACTION      = 'universal_telegram_settings_save';

	/**
	 * Positive-integer retention/timing fields, in display order.
	 *
	 * @var array<int, string>
	 */
	private const INTEGER_FIELDS = array(
		'telegram_message_retention_days',
		'telegram_delivery_log_retention_days',
		'telegram_max_pending_seconds',
		'telegram_webhook_max_body_bytes',
		'telegram_stale_pending_alert_seconds',
		'telegram_rate_limit_fallback_wait_seconds',
		'telegram_webhook_rotation_max_pending_hours',
		'event_retention_days',
		'dispatch_log_retention_days',
		'fatal_marker_retention_days',
	);

	/**
	 * Field name => visible label.
	 *
	 * @var array<string, string>
	 */
	private const INTEGER_FIELD_LABELS = array(
		'telegram_message_retention_days'             => 'Telegram message retention (days)',
		'telegram_delivery_log_retention_days'        => 'Telegram delivery log retention (days)',
		'telegram_max_pending_seconds'                => 'Telegram max pending time (seconds)',
		'telegram_webhook_max_body_bytes'             => 'Telegram webhook max body size (bytes)',
		'telegram_stale_pending_alert_seconds'        => 'Telegram stale-pending alert threshold (seconds)',
		'telegram_rate_limit_fallback_wait_seconds'   => 'Telegram rate-limit fallback wait (seconds)',
		'telegram_webhook_rotation_max_pending_hours' => 'Telegram webhook rotation max pending (hours)',
		'event_retention_days'                        => 'Event retention (days)',
		'dispatch_log_retention_days'                 => 'Dispatch log retention (days)',
		'fatal_marker_retention_days'                 => 'Fatal-error marker retention (days)',
	);

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Reads/writes the current plugin-wide configuration.
	 */
	public function __construct( private readonly Settings $settings ) {}

	/**
	 * The admin-post save handler.
	 */
	public function handle_request(): void {
		if ( ! current_user_can( CapabilityRegistrar::MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'universal-telegram' ), '', 403 );
		}

		check_admin_referer( self::NONCE_ACTION );

		$input = isset( $_POST['universal_telegram_settings'] ) && is_array( $_POST['universal_telegram_settings'] )
			? wp_unslash( $_POST['universal_telegram_settings'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			: array();

		$sanitized = $this->settings->sanitize( array_merge( $this->settings->get(), $input ) );
		update_option( Settings::OPTION_NAME, $sanitized );

		$this->redirect_and_exit( admin_url( 'admin.php?page=' . HubPage::SLUG . '&tab=' . self::TAB_ID ) );
	}

	/**
	 * Renders this tab's content only (no outer .wrap/<h1> — owned by
	 * HubPage).
	 */
	public function render_tab_content(): void {
		if ( ! current_user_can( CapabilityRegistrar::MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'universal-telegram' ) );
		}

		$values = $this->settings->get();

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE_ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ADMIN_POST_ACTION ) . '" />';

		echo '<p><label><input type="checkbox" name="universal_telegram_settings[remove_data_on_uninstall]" value="1" ' .
			checked( ! empty( $values['remove_data_on_uninstall'] ), true, false ) . ' /> ' .
			esc_html__( 'Remove all plugin data on uninstall', 'universal-telegram' ) . '</label></p>';

		echo '<table class="form-table"><tbody>';
		foreach ( self::INTEGER_FIELDS as $field ) {
			printf(
				'<tr><th><label for="ut-settings-%1$s">%2$s</label></th><td><input type="number" min="1" id="ut-settings-%1$s" name="universal_telegram_settings[%1$s]" value="%3$s" /></td></tr>',
				esc_attr( $field ),
				esc_html__( self::INTEGER_FIELD_LABELS[ $field ], 'universal-telegram' ), // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
				esc_attr( (string) $values[ $field ] )
			);
		}
		echo '</tbody></table>';

		submit_button();
		echo '</form>';
	}

	/**
	 * Redirects and terminates the request. Overridden by tests.
	 *
	 * @param string $url The destination URL.
	 */
	protected function redirect_and_exit( string $url ): void {
		wp_safe_redirect( $url );
		exit;
	}
}
