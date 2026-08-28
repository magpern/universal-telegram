<?php
/**
 * ADR-0044: the legacy website-chat surface is gone; the transport /
 * adapter surface remains.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Tests\Integration\Retirement;

use UniversalTelegram\Core\Plugin;
use UniversalTelegram\Persistence\Migrator;
use WP_UnitTestCase;

/**
 * @coversNothing
 */
final class LegacySurfaceAbsenceTest extends WP_UnitTestCase {

	public function test_no_legacy_conversation_rest_route_is_registered(): void {
		do_action( 'rest_api_init' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WooCommerce.Commenting.CommentHooks.MissingHookComment -- firing a WordPress core hook in a test.

		$routes = array_keys( rest_get_server()->get_routes() );

		foreach ( $routes as $route ) {
			$this->assertStringNotContainsString( '/universal-telegram/v1/conversations', $route );
			$this->assertStringNotContainsString( '/universal-telegram/v1/visitor', $route );
		}

		// The transport + adapter routes DO remain.
		$this->assertContains( '/universal-telegram/v1/webhook/(?P<bot_uuid>[0-9a-f-]{36})', $routes );
		$this->assertTrue(
			(bool) array_filter( $routes, static fn ( $r ) => str_contains( $r, '/universal-telegram/v1/support-chat/' ) ),
			'the Support Chat Contract routes must remain registered'
		);
	}

	public function test_no_chat_widget_or_visitor_tracker_asset_is_enqueued_on_the_front_end(): void {
		do_action( 'wp_enqueue_scripts' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WooCommerce.Commenting.CommentHooks.MissingHookComment -- firing a WordPress core hook in a test.

		global $wp_scripts, $wp_styles;

		foreach ( array_merge( (array) ( $wp_scripts->registered ?? array() ), (array) ( $wp_styles->registered ?? array() ) ) as $handle => $dep ) {
			$this->assertStringNotContainsString( 'chat-widget', (string) $handle );
			$this->assertStringNotContainsString( 'visitor-tracker', (string) $handle );
		}
	}

	public function test_the_cutover_and_quiescence_cli_commands_are_gone_and_purge_is_present(): void {
		$this->assertFalse( class_exists( 'UniversalTelegram\\Migration\\Cli\\CutoverCommand' ) );
		$this->assertFalse( class_exists( 'UniversalTelegram\\Migration\\Cli\\QuiescenceCommand' ) );
		$this->assertFalse( class_exists( 'UniversalTelegram\\Migration\\QuiescenceGate' ) );
		$this->assertFalse( class_exists( 'UniversalTelegram\\Migration\\CutoverActivationService' ) );
		$this->assertFalse( class_exists( 'UniversalTelegram\\SupportChatAdapter\\Migration\\LegacyExportServiceV1' ) );
		$this->assertFalse( class_exists( 'UniversalTelegram\\SupportChatAdapter\\Cli\\BindingImportCommand' ) );

		$this->assertTrue( class_exists( 'UniversalTelegram\\Administration\\Cli\\LegacyChatPurgeCommand' ) );
	}

	public function test_no_legacy_conversation_ai_or_operator_workflow_class_survives(): void {
		foreach ( array(
			'UniversalTelegram\\Conversations\\ConversationRepository',
			'UniversalTelegram\\Conversations\\MessageRepository',
			'UniversalTelegram\\Conversations\\ConversationNoteRepository',
			'UniversalTelegram\\Conversations\\OperatorAvailabilityRepository',
			'UniversalTelegram\\AI\\Draft\\AiDraftRepository',
			'UniversalTelegram\\ChatWidget\\ChatWidgetAssets',
			'UniversalTelegram\\Administration\\Hub\\HubPage_Conversations',
			'UniversalTelegram\\Automations\\Digest\\VisitorDigestSweep',
			'UniversalTelegram\\Automations\\Intelligence\\OperationalSummarySweep',
		) as $gone ) {
			$this->assertFalse( class_exists( $gone ), "{$gone} must be gone (ADR-0044)" );
		}

		// The retained adapter identity mapping is a different class.
		$this->assertTrue( class_exists( 'UniversalTelegram\\SupportChatAdapter\\Identity\\OperatorIdentityMapRepository' ) );
	}

	public function test_transport_and_adapter_and_alert_services_are_wired(): void {
		$plugin = Plugin::instance();

		$this->assertNotNull( $plugin->bot_profile_repository() );
		$this->assertNotNull( $plugin->destination_repository() );
		$this->assertNotNull( $plugin->webhook_controller() );
		$this->assertNotNull( $plugin->webhook_registration_coordinator() );
		$this->assertNotNull( $plugin->outbound_message_repository() );
		$this->assertNotNull( $plugin->worker_runner() );
		$this->assertNotNull( $plugin->circuit_breaker() );
		$this->assertNotNull( $plugin->rate_limiter() );
		$this->assertNotNull( $plugin->notification_rule_repository() );
		$this->assertNotNull( $plugin->event_history_repository() );

		// Generic operational alert schema is retained.
		global $wpdb;
		$this->assertTrue( Migrator::table_exists( $wpdb->prefix . Migrator::OPERATIONAL_ALERT_STATE_TABLE ) );
		$this->assertTrue( Migrator::table_exists( $wpdb->prefix . Migrator::SUPPORT_CHAT_BINDINGS_TABLE ) );
	}

	public function test_fresh_schema_has_no_legacy_table(): void {
		global $wpdb;

		( new Migrator( new \UniversalTelegram\Persistence\MigrationLock() ) )->maybe_migrate();

		foreach ( Migrator::LEGACY_TABLES as $legacy ) {
			$this->assertFalse( Migrator::table_exists( $wpdb->prefix . $legacy ), "legacy table {$legacy} must not exist" );
		}
	}
}
