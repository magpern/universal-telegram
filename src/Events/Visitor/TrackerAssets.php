<?php
/**
 * Cache-safe tracker asset enqueue and configuration.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Events\Visitor;

use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;
use UniversalTelegram\Core\Configuration\Settings;
use UniversalTelegram\Integrations\WooCommerce\WooCommerceSupport;

/**
 * Enqueues assets/js/visitor-tracker.js and its inline configuration.
 * The configuration is static per URL — computed entirely from the
 * current request's own settings/page-context, never from a per-user or
 * per-session value — so it is safe to be baked into any full-page-cached
 * HTML variant; IngestController re-validates every setting live on each
 * request regardless of what a stale cached config claims (M04 plan §4.4).
 */
final class TrackerAssets {

	/**
	 * Constructor.
	 *
	 * @param Settings              $settings             Reads the current visitor tracking configuration.
	 * @param PageContext           $page_context          Server-rendered page-type/path/commerce detection.
	 * @param WooCommerceSupport    $woocommerce_support   Whether WooCommerce is active.
	 * @param CapabilityRegistrar   $capability_registrar  Used to check the administrator-exclusion setting.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly PageContext $page_context,
		private readonly WooCommerceSupport $woocommerce_support,
		private readonly CapabilityRegistrar $capability_registrar
	) {}

	/**
	 * Enqueues the tracker and its config, when appropriate for the
	 * current request.
	 */
	public function enqueue(): void {
		if ( ! $this->should_enqueue() ) {
			return;
		}

		$settings_values = $this->settings->get();

		if ( ! $settings_values['visitor_tracking_enabled'] ) {
			return;
		}

		if ( $settings_values['visitor_exclude_administrators'] && current_user_can( CapabilityRegistrar::MANAGE ) ) {
			return;
		}

		if ( ! defined( 'UNIVERSAL_TELEGRAM_PLUGIN_FILE' ) ) {
			return;
		}

		$url = plugins_url( 'assets/js/visitor-tracker.js', UNIVERSAL_TELEGRAM_PLUGIN_FILE );
		wp_enqueue_script( 'universal-telegram-visitor-tracker', $url, array(), UNIVERSAL_TELEGRAM_VERSION, true );

		$commerce_active = $settings_values['visitor_family_commerce'] && $this->woocommerce_support->is_active();

		$config = array(
			'enabled'         => true,
			'consentMode'     => $settings_values['visitor_consent_mode'],
			'endpoint'        => rest_url( 'universal-telegram/v1/visitor-events' ),
			'initialPath'     => $this->page_context->path(),
			'initialPageType' => $this->page_context->page_type(),
			'commerce'        => $commerce_active,
			'productId'       => $commerce_active ? $this->page_context->product_id() : null,
			'isCheckout'      => $commerce_active && $this->page_context->is_checkout_page(),
		);

		wp_add_inline_script(
			'universal-telegram-visitor-tracker',
			'window.UniversalTelegramVisitorConfig = ' . wp_json_encode( $config ) . ';',
			'before'
		);
	}

	/**
	 * Whether the tracker should be considered for the current request at
	 * all — nine excluded contexts where no frontend page render happens
	 * (admin, AJAX, cron, REST, feeds, robots.txt, trackbacks, JSON
	 * requests, and wp-login.php).
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

		return true;
	}
}
