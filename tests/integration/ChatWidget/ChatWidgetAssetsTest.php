<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\ChatWidget;

use UniversalTelegram\ChatWidget\ChatWidgetAssets;
use UniversalTelegram\ChatWidget\ChatWidgetAvailability;
use UniversalTelegram\Conversations\ChatProfileResolver;
use UniversalTelegram\Core\Configuration\Settings;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;
use UniversalTelegram\Telegram\Configuration\DestinationKind;
use UniversalTelegram\Telegram\Configuration\DestinationRepository;
use WP_UnitTestCase;

final class ChatWidgetAssetsTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();

		global $wp_scripts, $wp_styles;
		$wp_scripts = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$wp_styles  = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}

	protected function tearDown(): void {
		wp_deregister_script( 'universal-telegram-chat-widget' );
		wp_deregister_style( 'universal-telegram-chat-widget' );
		parent::tearDown();
	}

	private function make_eligible_destination(): void {
		$schema_health = new SchemaHealth();
		$bot           = ( new BotProfileRepository( $schema_health, new CredentialVault() ) )->create( 'Support Bot', 'token' );
		( new DestinationRepository( $schema_health ) )->create( $bot->id(), DestinationKind::SUPERGROUP, '-100123', null, 'Support group' );
	}

	private function assets(): ChatWidgetAssets {
		$schema_health = new SchemaHealth();
		$bots          = new BotProfileRepository( $schema_health, new CredentialVault() );
		$destinations  = new DestinationRepository( $schema_health );

		return new ChatWidgetAssets( new ChatWidgetAvailability( new Settings(), new ChatProfileResolver( $bots, $destinations ) ) );
	}

	public function test_disabled_setting_enqueues_nothing_and_prints_nothing(): void {
		update_option( Settings::OPTION_NAME, array_merge( ( new Settings() )->defaults(), array( 'chat_widget_enabled' => false ) ) );
		$this->make_eligible_destination();

		$this->go_to( home_url( '/' ) );
		$assets = $this->assets();
		$assets->enqueue();

		$this->assertFalse( wp_script_is( 'universal-telegram-chat-widget', 'enqueued' ) );
		$this->assertFalse( wp_style_is( 'universal-telegram-chat-widget', 'enqueued' ) );

		ob_start();
		$assets->print_config();
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_enabled_with_eligible_destination_enqueues_script_and_style(): void {
		update_option( Settings::OPTION_NAME, array_merge( ( new Settings() )->defaults(), array( 'chat_widget_enabled' => true ) ) );
		$this->make_eligible_destination();

		$this->go_to( home_url( '/' ) );
		$assets = $this->assets();
		$assets->enqueue();

		$this->assertTrue( wp_script_is( 'universal-telegram-chat-widget', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'universal-telegram-chat-widget', 'enqueued' ) );
	}

	public function test_no_eligible_destination_enqueues_nothing(): void {
		update_option( Settings::OPTION_NAME, array_merge( ( new Settings() )->defaults(), array( 'chat_widget_enabled' => true ) ) );

		$this->go_to( home_url( '/' ) );
		$assets = $this->assets();
		$assets->enqueue();

		$this->assertFalse( wp_script_is( 'universal-telegram-chat-widget', 'enqueued' ) );
	}

	public function test_config_island_contains_only_static_rest_url_and_namespace(): void {
		update_option( Settings::OPTION_NAME, array_merge( ( new Settings() )->defaults(), array( 'chat_widget_enabled' => true ) ) );
		$this->make_eligible_destination();

		$this->go_to( home_url( '/' ) );
		$assets = $this->assets();

		ob_start();
		$assets->print_config();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'type="application/json"', $output );
		$this->assertStringContainsString( 'id="ut-chat-widget-config"', $output );
		$this->assertStringContainsString( 'universal-telegram/v1', $output );
		$this->assertStringNotContainsString( 'conversation_uuid', $output );
		$this->assertStringNotContainsString( 'secret', $output );
		$this->assertSame( 1, substr_count( $output, '</script>' ) );
	}

	public function test_config_island_is_identical_across_two_anonymous_requests(): void {
		update_option( Settings::OPTION_NAME, array_merge( ( new Settings() )->defaults(), array( 'chat_widget_enabled' => true ) ) );
		$this->make_eligible_destination();

		$this->go_to( home_url( '/' ) );
		$first_assets = $this->assets();
		ob_start();
		$first_assets->print_config();
		$first = ob_get_clean();

		$this->go_to( home_url( '/some-other-page' ) );
		$second_assets = $this->assets();
		ob_start();
		$second_assets->print_config();
		$second = ob_get_clean();

		$this->assertSame( $first, $second );
	}

	public function test_z_nothing_is_enqueued_during_a_rest_request(): void {
		update_option( Settings::OPTION_NAME, array_merge( ( new Settings() )->defaults(), array( 'chat_widget_enabled' => true ) ) );
		$this->make_eligible_destination();

		$this->go_to( home_url( '/' ) );

		if ( ! defined( 'REST_REQUEST' ) ) {
			define( 'REST_REQUEST', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
		}

		$assets = $this->assets();
		$assets->enqueue();

		$this->assertFalse( wp_script_is( 'universal-telegram-chat-widget', 'enqueued' ) );
	}
}
