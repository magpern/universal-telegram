<?php
/**
 * Same-origin login/registration URL resolution for the logged-out widget state.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\ChatWidget;

/**
 * Resolves the login and (when available) registration URLs the logged-out
 * widget shell links to (M06.3.1, ADR-0025). Prefers WooCommerce's own My
 * Account page when WooCommerce is active and the page is configured;
 * otherwise falls back to WordPress core's own login/registration routes.
 * WooCommerce is never a hard dependency. The return URL is always derived
 * from this same request's own host via home_url() plus the server's own
 * REQUEST_URI — never a caller-supplied value — so it is same-origin by
 * construction.
 */
final class AccountUrlResolver {

	/**
	 * The current request's own URL, safe to redirect back to after
	 * sign-in/registration.
	 *
	 * @return string
	 */
	public function current_url(): string {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		return esc_url_raw( home_url( $request_uri ) );
	}

	/**
	 * The login URL, redirecting back to the given same-origin URL.
	 *
	 * @param string $return_url The current request's own URL (see current_url()).
	 *
	 * @return string
	 */
	public function login_url( string $return_url ): string {
		$account_page = $this->woocommerce_myaccount_url();

		if ( null !== $account_page ) {
			return add_query_arg( 'redirect_to', rawurlencode( $return_url ), $account_page );
		}

		return wp_login_url( $return_url );
	}

	/**
	 * The registration URL, or null when the site does not currently allow
	 * new-user registration (core's own `users_can_register` option) — the
	 * widget never shows a "Create account" control in that case.
	 *
	 * @param string $return_url The current request's own URL (see current_url()).
	 *
	 * @return string|null
	 */
	public function register_url( string $return_url ): ?string {
		if ( ! get_option( 'users_can_register' ) ) {
			return null;
		}

		$account_page = $this->woocommerce_myaccount_url();

		if ( null !== $account_page ) {
			return add_query_arg( 'redirect_to', rawurlencode( $return_url ), $account_page );
		}

		return wp_registration_url();
	}

	/**
	 * WooCommerce's own My Account page URL, when WooCommerce is active and
	 * the page is configured — null otherwise, so callers fall back to core.
	 *
	 * @return string|null
	 */
	private function woocommerce_myaccount_url(): ?string {
		if ( ! function_exists( 'wc_get_page_permalink' ) ) {
			return null;
		}

		$url = (string) wc_get_page_permalink( 'myaccount' );

		return '' !== $url ? $url : null;
	}
}
