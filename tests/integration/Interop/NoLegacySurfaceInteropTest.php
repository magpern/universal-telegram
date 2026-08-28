<?php
/**
 * ADR-0044 interop: with BOTH plugins active, Universal Telegram exposes no
 * legacy website-chat surface — no conversation REST route, no chat widget,
 * no legacy conversation/message/note table — while Support Chat owns chat.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Tests\Integration\Interop;

/**
 * @coversNothing
 */
final class NoLegacySurfaceInteropTest extends InteropTestCase {

	public function test_no_legacy_universal_telegram_conversation_rest_route_is_registered(): void {
		do_action( 'rest_api_init' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WooCommerce.Commenting.CommentHooks.MissingHookComment -- firing a WordPress core hook in a test.

		$routes = array_keys( rest_get_server()->get_routes() );

		foreach ( $routes as $route ) {
			$this->assertStringNotContainsString( '/universal-telegram/v1/conversations', $route );
			$this->assertStringNotContainsString( '/universal-telegram/v1/visitor', $route );
		}

		// The transport webhook and the Support Chat Contract routes remain.
		$this->assertContains( '/universal-telegram/v1/webhook/(?P<bot_uuid>[0-9a-f-]{36})', $routes );
		$this->assertTrue(
			(bool) array_filter( $routes, static fn ( $r ) => str_contains( $r, '/universal-telegram/v1/support-chat/' ) )
		);

		// Support Chat owns the visitor-facing chat routes.
		$this->assertTrue(
			(bool) array_filter( $routes, static fn ( $r ) => str_contains( $r, '/universal-support-chat/' ) )
		);
	}

	public function test_no_universal_telegram_chat_widget_asset_is_enqueued(): void {
		do_action( 'wp_enqueue_scripts' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WooCommerce.Commenting.CommentHooks.MissingHookComment -- firing a WordPress core hook in a test.

		global $wp_scripts;

		foreach ( array_keys( (array) ( $wp_scripts->registered ?? array() ) ) as $handle ) {
			$this->assertStringNotContainsString( 'universal-telegram-chat', (string) $handle );
			$this->assertStringNotContainsString( 'universal-telegram-visitor', (string) $handle );
		}
	}

	public function test_a_full_round_trip_never_creates_a_legacy_universal_telegram_table(): void {
		$conversation_uuid = $this->create_sc_conversation();
		$this->ensure_ut_channel_case( $conversation_uuid );
		$this->sc_outbound_client->deliver_message( 'universal-telegram', $conversation_uuid, wp_generate_uuid4(), 'Round trip', 'Operator' );

		global $wpdb;
		$legacy = $wpdb->get_col(
			"SHOW TABLES LIKE '{$wpdb->prefix}universal_telegram_conversation%'" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed prefix.
		);
		$this->assertSame( array(), $legacy );

		$this->assertSame(
			array(),
			$wpdb->get_col( "SHOW TABLES LIKE '{$wpdb->prefix}universal_telegram_operator_identities'" ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed prefix.
		);
	}
}
