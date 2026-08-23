<?php
/**
 * Cache-safe chat widget asset enqueue and configuration.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\ChatWidget;

use UniversalTelegram\AI\Config\AIProviderRepository;
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
	private const VALID_PRESETS = array( 'theme', 'classic', 'modern', 'minimal' );

	/**
	 * Constructor.
	 *
	 * @param ChatWidgetAvailability $availability   Whether the widget should run on this request at all.
	 * @param Settings               $settings       Reads the current preset/geometry/motion/participant-label configuration.
	 * @param AccountUrlResolver     $account_urls   Resolves the logged-out login/registration links (M06.3.1, ADR-0025).
	 * @param AIProviderRepository   $ai_provider    Reads the AI enablement flag and public disclosure text/version (M09, docs/adr/0028 decision 1) — never the credential.
	 */
	public function __construct(
		private readonly ChatWidgetAvailability $availability,
		private readonly Settings $settings,
		private readonly AccountUrlResolver $account_urls,
		private readonly AIProviderRepository $ai_provider
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

		$plugin_dir  = plugin_dir_path( UNIVERSAL_TELEGRAM_PLUGIN_FILE );
		$script_path = $plugin_dir . 'assets/js/chat-widget.js';
		$style_path  = $plugin_dir . 'assets/css/chat-widget-' . $this->preset() . '.css';

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			plugins_url( 'assets/js/chat-widget.js', UNIVERSAL_TELEGRAM_PLUGIN_FILE ),
			array(),
			$this->asset_version( $script_path ),
			true
		);

		wp_enqueue_style(
			self::STYLE_HANDLE,
			plugins_url( 'assets/css/chat-widget-' . $this->preset() . '.css', UNIVERSAL_TELEGRAM_PLUGIN_FILE ),
			array(),
			$this->asset_version( $style_path )
		);
	}

	/**
	 * Per-file cache-busting version: the file's own mtime when readable,
	 * so a code change to chat-widget.js or a preset stylesheet is always
	 * fetched fresh by browsers on the very next enqueue, regardless of
	 * whether the plugin version constant was bumped. Falls back to the
	 * plugin version when the file cannot be stat'd (e.g. a stripped
	 * release build without filesystem access).
	 *
	 * @param string $path Absolute path to the enqueued file.
	 * @return string
	 */
	private function asset_version( string $path ): string {
		if ( is_readable( $path ) ) {
			$mtime = filemtime( $path );

			if ( false !== $mtime ) {
				return (string) $mtime;
			}
		}

		return defined( 'UNIVERSAL_TELEGRAM_VERSION' ) ? UNIVERSAL_TELEGRAM_VERSION : 'unknown';
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

		return in_array( $preset, self::VALID_PRESETS, true ) ? $preset : 'theme';
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

		// loggedIn/nonce/loginUrl/registerUrl are per-request (M06.3.1,
		// ADR-0025): identical among anonymous visitors of the same page
		// (still cache-safe for that audience — no full-page-cache layer
		// exists for authenticated requests in this stack), and personalized
		// only once a visitor is actually authenticated. The nonce is never
		// printed for a logged-out request.
		$return_url = $this->account_urls->current_url();
		$logged_in  = is_user_logged_in();

		$config = array(
			'restUrl'              => rest_url( 'universal-telegram/v1' ),
			'namespace'            => 'universal-telegram/v1',
			'geometry'             => in_array( $values['chat_widget_geometry'], array( 'round', 'square' ), true ) ? $values['chat_widget_geometry'] : 'round',
			'motionDefault'        => in_array( $values['chat_widget_motion_default'], array( 'standard', 'reduced' ), true ) ? $values['chat_widget_motion_default'] : 'standard',
			'labelVisitor'         => (string) $values['chat_widget_participant_label_visitor'],
			'labelOperator'        => (string) $values['chat_widget_participant_label_operator'],
			'loggedIn'             => $logged_in,
			'nonce'                => $logged_in ? wp_create_nonce( 'wp_rest' ) : null,
			'loginUrl'             => $this->account_urls->login_url( $return_url ),
			'registerUrl'          => $this->account_urls->register_url( $return_url ),
			// First name only, never a full name/username/email — a purely
			// cosmetic greeting, personalized only for an actually-
			// authenticated request exactly like loggedIn/nonce above.
			'firstName'            => $logged_in ? $this->resolve_first_name() : null,
			// Identical for every anonymous visitor of a given page — a
			// pure function of stored settings like geometry/preset above,
			// so it stays cache-safe (M06.3.1 addendum).
			'anonymousChatAllowed' => (bool) $values['chat_widget_allow_anonymous'],
		);

		// M09, docs/adr/0028: public, fixed, non-secret configuration only
		// — the enablement flag and the current disclosure text/version.
		// Never the API key or model identifier, and identical for every
		// visitor of a given page, so this stays as cache-safe as every
		// other field above; a text/version edit simply invalidates the
		// cached fragment like any other config change.
		$ai_config           = $this->ai_provider->get();
		$config['aiEnabled'] = null !== $ai_config && $ai_config->is_ready();
		$config['aiAckText'] = null !== $ai_config ? $ai_config->ack_text() : '';

		printf(
			'<script type="application/json" id="%1$s">%2$s</script>',
			esc_attr( self::CONFIG_ID ),
			wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT )
		);
	}

	/**
	 * A short, purely cosmetic first name for the widget's greeting —
	 * never the full display name, username, or email. Falls back to the
	 * first word of the WordPress display name when no first name is set
	 * on the account, and to null (no personalized greeting) when neither
	 * yields anything usable.
	 *
	 * @return string|null
	 */
	private function resolve_first_name(): ?string {
		$user       = wp_get_current_user();
		$first_name = trim( (string) $user->first_name );

		if ( '' === $first_name ) {
			$display_name = trim( (string) $user->display_name );
			$first_word   = strtok( $display_name, " \t\n" );
			$first_name   = false === $first_word ? '' : trim( $first_word );
		}

		if ( '' === $first_name ) {
			return null;
		}

		return mb_substr( $first_name, 0, 40, 'UTF-8' );
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
