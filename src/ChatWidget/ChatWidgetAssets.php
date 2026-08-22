<?php
/**
 * Cache-safe chat widget asset enqueue and configuration.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\ChatWidget;

use UniversalTelegram\Core\Configuration\Settings;

/**
 * Enqueues assets/js/chat-widget.js and one of the three compiled preset
 * stylesheets (M06.3, ADR-0024: assets/css/chat-widget-classic.css,
 * -modern.css, -minimal.css — chosen by the admin-set chat_widget_preset
 * setting, never a custom-CSS textarea or external URL), and prints their
 * static configuration as a non-executable JSON data island (M06 plan §4)
 * — never `wp_add_inline_script()`, which emits an executable inline
 * `<script>` and would need a CSP nonce/hash grant this plugin does not
 * otherwise require. The config is a pure function of stored settings —
 * identical for every anonymous visitor on a given request, so it remains
 * safe to bake into any full-page-cached HTML variant; no conversation_uuid,
 * secret, or idempotency key is ever passed into it. A global preset/
 * appearance change becomes visible to an already-cached visitor only
 * once the site's own page cache purges or naturally expires — this class
 * makes no attempt to invalidate any third-party full-page cache.
 */
final class ChatWidgetAssets {

	private const SCRIPT_HANDLE = 'universal-telegram-chat-widget';
	private const STYLE_HANDLE  = 'universal-telegram-chat-widget';
	private const CONFIG_ID     = 'ut-chat-widget-config';

	/**
	 * The only preset values ChatWidgetAssets will ever enqueue a
	 * stylesheet for — mirrors Settings::sanitize()'s own enum, defended
	 * here again so a corrupted option value can never resolve to an
	 * arbitrary file path.
	 *
	 * @var array<int, string>
	 */
	private const VALID_PRESETS = array( 'classic', 'modern', 'minimal' );

	/**
	 * Constructor.
	 *
	 * @param ChatWidgetAvailability $availability Whether the widget should run on this request at all.
	 * @param Settings               $settings     Reads the current preset/geometry/motion/participant-label configuration.
	 */
	public function __construct(
		private readonly ChatWidgetAvailability $availability,
		private readonly Settings $settings
	) {}

	/**
	 * Enqueues the widget's script and stylesheet, when appropriate for
	 * the current request.
	 */
	public function enqueue(): void {
		if ( ! $this->should_enqueue() ) {
			return;
		}

		if ( ! defined( 'UNIVERSAL_TELEGRAM_PLUGIN_FILE' ) ) {
			return;
		}

		$version = defined( 'UNIVERSAL_TELEGRAM_VERSION' ) ? UNIVERSAL_TELEGRAM_VERSION : 'unknown';

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			plugins_url( 'assets/js/chat-widget.js', UNIVERSAL_TELEGRAM_PLUGIN_FILE ),
			array(),
			$version,
			true
		);

		wp_enqueue_style(
			self::STYLE_HANDLE,
			plugins_url( 'assets/css/chat-widget-' . $this->preset() . '.css', UNIVERSAL_TELEGRAM_PLUGIN_FILE ),
			array(),
			$version
		);
	}

	/**
	 * The stored chat_widget_preset value, defended again against a
	 * corrupted option value so it can never resolve to an arbitrary file
	 * path — this is a pure function of stored settings, never the
	 * request, so it stays identical for every anonymous visitor and
	 * therefore cache-safe.
	 *
	 * @return string
	 */
	private function preset(): string {
		$preset = (string) $this->settings->get()['chat_widget_preset'];

		return in_array( $preset, self::VALID_PRESETS, true ) ? $preset : 'modern';
	}

	/**
	 * Prints the static configuration data island, hooked at a priority
	 * below WordPress core's own footer-script printer
	 * (`wp_print_footer_scripts`, hooked to `wp_footer` at priority 20) so
	 * it is always present in the DOM before the enqueued, in-footer
	 * script executes. Only prints when the widget is actually being
	 * enqueued for this request.
	 */
	public function print_config(): void {
		if ( ! $this->should_enqueue() ) {
			return;
		}

		$values = $this->settings->get();

		$config = array(
			'restUrl'       => rest_url( 'universal-telegram/v1' ),
			'namespace'     => 'universal-telegram/v1',
			'geometry'      => in_array( $values['chat_widget_geometry'], array( 'round', 'square' ), true ) ? $values['chat_widget_geometry'] : 'round',
			'motionDefault' => in_array( $values['chat_widget_motion_default'], array( 'standard', 'reduced' ), true ) ? $values['chat_widget_motion_default'] : 'standard',
			'labelVisitor'  => (string) $values['chat_widget_participant_label_visitor'],
			'labelOperator' => (string) $values['chat_widget_participant_label_operator'],
		);

		printf(
			'<script type="application/json" id="%1$s">%2$s</script>',
			esc_attr( self::CONFIG_ID ),
			wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT )
		);
	}

	/**
	 * Whether the widget should be considered for the current request at
	 * all — the same nine excluded contexts TrackerAssets already uses
	 * (admin, AJAX, cron, REST, feeds, robots.txt, trackbacks, JSON
	 * requests, wp-login.php), plus availability itself.
	 *
	 * @return bool
	 */
	private function should_enqueue(): bool {
		if ( is_admin() ) {
			return false;
		}

		if ( wp_doing_ajax() ) {
			return false;
		}

		if ( wp_doing_cron() ) {
			return false;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}

		if ( function_exists( 'is_feed' ) && is_feed() ) {
			return false;
		}

		if ( function_exists( 'is_robots' ) && is_robots() ) {
			return false;
		}

		if ( function_exists( 'is_trackback' ) && is_trackback() ) {
			return false;
		}

		if ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() ) {
			return false;
		}

		if ( isset( $GLOBALS['pagenow'] ) && 'wp-login.php' === $GLOBALS['pagenow'] ) {
			return false;
		}

		return $this->availability->is_available();
	}
}
