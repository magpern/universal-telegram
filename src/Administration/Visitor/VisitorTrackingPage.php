<?php
/**
 * Visitor tracking settings admin page.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Administration\Visitor;

use UniversalTelegram\Administration\Diagnostics\DiagnosticsPage;
use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;
use UniversalTelegram\Core\Configuration\Settings;

/**
 * Gated on the existing CapabilityRegistrar::MANAGE capability (ADR-0010)
 * — no new capability constant is introduced (M04 plan §6). Every action
 * independently re-verifies both the capability and its own nonce, never
 * relying solely on menu-registration-time gating, mirroring
 * RuleBuilderRequestHandler's exact existing pattern.
 *
 * Not declared final: tests override redirect_and_exit() to avoid a real
 * exit call terminating the test process, matching
 * RuleBuilderRequestHandler's exact existing precedent.
 */
class VisitorTrackingPage {

	public const SLUG              = 'universal-telegram-visitor-tracking';
	public const ADMIN_POST_ACTION = 'universal_telegram_visitor_tracking_save';
	public const NONCE_ACTION      = 'universal_telegram_visitor_tracking_save';

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Reads/writes the current visitor tracking configuration.
	 */
	public function __construct( private readonly Settings $settings ) {}

	/**
	 * Registers the admin submenu entry.
	 */
	public function register_menu(): void {
		add_submenu_page(
			DiagnosticsPage::SLUG,
			__( 'Visitor Tracking', 'universal-telegram' ),
			__( 'Visitor Tracking', 'universal-telegram' ),
			CapabilityRegistrar::MANAGE,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * The admin-post save handler.
	 */
	public function handle_request(): void {
		if ( ! current_user_can( CapabilityRegistrar::MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'universal-telegram' ), '', 403 );
		}

		check_admin_referer( self::NONCE_ACTION );

		$input = isset( $_POST['visitor_settings'] ) && is_array( $_POST['visitor_settings'] )
			? wp_unslash( $_POST['visitor_settings'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			: array();

		if ( isset( $input['visitor_click_target_allowlist'] ) && is_string( $input['visitor_click_target_allowlist'] ) ) {
			$input['visitor_click_target_allowlist'] = array_filter( array_map( 'trim', explode( "\n", $input['visitor_click_target_allowlist'] ) ) );
		}

		$sanitized = $this->settings->sanitize( array_merge( $this->settings->get(), $input ) );
		update_option( Settings::OPTION_NAME, $sanitized );

		$this->redirect_and_exit( admin_url( 'admin.php?page=' . self::SLUG ) );
	}

	/**
	 * Renders the page.
	 */
	public function render(): void {
		if ( ! current_user_can( CapabilityRegistrar::MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'universal-telegram' ) );
		}

		$values = $this->settings->get();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Visitor Tracking', 'universal-telegram' ) . '</h1>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE_ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ADMIN_POST_ACTION ) . '" />';

		$this->checkbox( $values, 'visitor_tracking_enabled', __( 'Enable visitor tracking', 'universal-telegram' ) );
		$this->checkbox( $values, 'visitor_family_page_views', __( 'Page views', 'universal-telegram' ) );
		$this->checkbox( $values, 'visitor_family_navigation', __( 'Navigation', 'universal-telegram' ) );
		$this->checkbox( $values, 'visitor_family_search', __( 'Search', 'universal-telegram' ) );
		$this->checkbox( $values, 'visitor_family_clicks', __( 'Clicks', 'universal-telegram' ) );
		$this->checkbox( $values, 'visitor_family_errors', __( 'JavaScript errors', 'universal-telegram' ) );
		$this->checkbox( $values, 'visitor_family_commerce', __( 'WooCommerce (product views, classic add-to-cart, checkout entry)', 'universal-telegram' ) );
		$this->checkbox( $values, 'visitor_exclude_administrators', __( 'Exclude administrators', 'universal-telegram' ) );

		echo '<p><label>' . esc_html__( 'Consent mode', 'universal-telegram' ) . ' ';
		echo '<select name="visitor_settings[visitor_consent_mode]">';
		foreach ( array( 'required', 'disabled' ) as $mode ) {
			printf(
				'<option value="%1$s" %2$s>%1$s</option>',
				esc_attr( $mode ),
				selected( $values['visitor_consent_mode'], $mode, false )
			);
		}
		echo '</select></label></p>';
		echo '<p class="description">' . esc_html__(
			"This checks a signal from your consent tool before collecting data in the visitor's browser. It cannot be verified by the server and is not a substitute for your own compliance review.",
			'universal-telegram'
		) . '</p>';

		echo '<p><label>' . esc_html__( 'Sampling percent', 'universal-telegram' ) . ' ';
		echo '<input type="number" min="1" max="100" name="visitor_settings[visitor_sampling_percent]" value="' . esc_attr( (string) $values['visitor_sampling_percent'] ) . '" />';
		echo '</label></p>';

		echo '<p><label>' . esc_html__( 'Click target allowlist (one key per line, max 8)', 'universal-telegram' ) . '<br />';
		echo '<textarea name="visitor_settings[visitor_click_target_allowlist]" rows="4" cols="40">' . esc_textarea( implode( "\n", $values['visitor_click_target_allowlist'] ) ) . '</textarea>';
		echo '</label></p>';

		submit_button();
		echo '</form>';
		echo '</div>';
	}

	/**
	 * Renders one boolean settings checkbox.
	 *
	 * @param array<string, mixed> $values Current settings values.
	 * @param string               $field  The settings field name.
	 * @param string               $label  The visible label.
	 */
	private function checkbox( array $values, string $field, string $label ): void {
		printf(
			'<p><label><input type="checkbox" name="visitor_settings[%1$s]" value="1" %2$s /> %3$s</label></p>',
			esc_attr( $field ),
			checked( ! empty( $values[ $field ] ), true, false ),
			esc_html( $label )
		);
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
