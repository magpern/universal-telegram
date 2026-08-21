<?php
/**
 * Server-rendered page-type detection for visitor/browser events.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Events\Visitor;

/**
 * Reused by both the initial page-view payload and WooCommerce-gated
 * product/checkout detection (M04 plan §4.6) — server-rendered WordPress
 * conditionals, never a JS heuristic, so the result is identical for
 * classic and block-based themes/checkout alike.
 */
final class PageContext {

	/**
	 * The current request's page type, one of
	 * home|singular|search|archive|other (M04 plan §4.2).
	 *
	 * @return string
	 */
	public function page_type(): string {
		if ( is_front_page() || is_home() ) {
			return 'home';
		}

		if ( is_search() ) {
			return 'search';
		}

		if ( is_singular() ) {
			return 'singular';
		}

		if ( is_archive() ) {
			return 'archive';
		}

		return 'other';
	}

	/**
	 * The current request's path, leading-slash, no query/fragment,
	 * bounded to 190 bytes (M04 plan §4.2).
	 *
	 * @return string
	 */
	public function path(): string {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$parsed      = wp_parse_url( $request_uri );
		$path        = is_array( $parsed ) && isset( $parsed['path'] ) ? $parsed['path'] : '/';

		if ( '' === $path || '/' !== $path[0] ) {
			$path = '/' . ltrim( $path, '/' );
		}

		if ( strlen( $path ) > 190 ) {
			$path = substr( $path, 0, 190 );
		}

		return $path;
	}

	/**
	 * Whether the current request is a WooCommerce single product page.
	 *
	 * @return bool
	 */
	public function is_product(): bool {
		return function_exists( 'is_product' ) && is_product();
	}

	/**
	 * Whether the current request is the WooCommerce checkout page —
	 * true for both classic and block checkout equally, since it is a
	 * server-rendered conditional (M04 plan §4.6).
	 *
	 * @return bool
	 */
	public function is_checkout_page(): bool {
		return function_exists( 'is_checkout' ) && is_checkout();
	}
}
