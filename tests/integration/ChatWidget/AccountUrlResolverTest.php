<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\ChatWidget;

use UniversalTelegram\ChatWidget\AccountUrlResolver;
use WP_UnitTestCase;

final class AccountUrlResolverTest extends WP_UnitTestCase {

	public function test_current_url_is_same_origin(): void {
		$_SERVER['REQUEST_URI'] = '/some-page/?foo=bar';

		$resolver = new AccountUrlResolver();
		$url      = $resolver->current_url();

		$this->assertStringStartsWith( home_url(), $url );
		$this->assertStringContainsString( '/some-page/', $url );
	}

	public function test_login_url_falls_back_to_core_when_woocommerce_is_absent(): void {
		if ( function_exists( 'wc_get_page_permalink' ) ) {
			$this->markTestSkipped( 'WooCommerce is active in this matrix leg — see test_login_url_prefers_woocommerce_myaccount_when_active().' );
		}

		$resolver = new AccountUrlResolver();

		$url = $resolver->login_url( home_url( '/return-here/' ) );

		$this->assertStringContainsString( 'wp-login.php', $url );
	}

	public function test_login_url_prefers_woocommerce_myaccount_when_active(): void {
		if ( ! function_exists( 'wc_get_page_permalink' ) ) {
			$this->markTestSkipped( 'WooCommerce is not active in this matrix leg.' );
		}

		$resolver = new AccountUrlResolver();

		$url = $resolver->login_url( home_url( '/return-here/' ) );

		$this->assertStringNotContainsString( 'wp-login.php', $url );
	}

	public function test_register_url_is_null_when_registration_is_disabled(): void {
		update_option( 'users_can_register', 0 );
		$resolver = new AccountUrlResolver();

		$this->assertNull( $resolver->register_url( home_url( '/' ) ) );
	}

	public function test_register_url_is_present_when_registration_is_enabled(): void {
		update_option( 'users_can_register', 1 );
		$resolver = new AccountUrlResolver();

		$this->assertNotNull( $resolver->register_url( home_url( '/' ) ) );
	}
}
