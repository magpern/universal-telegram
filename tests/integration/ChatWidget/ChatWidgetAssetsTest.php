<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\ChatWidget;

use UniversalTelegram\ChatWidget\AccountUrlResolver;
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

		return new ChatWidgetAssets( new ChatWidgetAvailability( new Settings(), new ChatProfileResolver( $bots, $destinations ) ), new Settings(), new AccountUrlResolver() );
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
		// wp_json_encode() escapes forward slashes by default, so the
		// namespace/URL appear as universal-telegram\/v1 in the raw output.
		$this->assertStringContainsString( 'universal-telegram\\/v1', $output );
		$this->assertStringNotContainsString( 'conversation_uuid', $output );
		$this->assertStringNotContainsString( 'secret', $output );
		$this->assertSame( 1, substr_count( $output, '</script>' ) );
	}

	public function test_config_island_is_identical_across_two_anonymous_requests(): void {
		update_option( Settings::OPTION_NAME, array_merge( ( new Settings() )->defaults(), array( 'chat_widget_enabled' => true ) ) );
		$this->make_eligible_destination();
		wp_set_current_user( 0 );

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

	public function test_enqueue_selects_the_stylesheet_matching_the_stored_preset(): void {
		update_option(
			Settings::OPTION_NAME,
			array_merge(
				( new Settings() )->defaults(),
				array(
					'chat_widget_enabled' => true,
					'chat_widget_preset'  => 'minimal',
				)
			)
		);
		$this->make_eligible_destination();

		$this->go_to( home_url( '/' ) );
		$assets = $this->assets();
		$assets->enqueue();

		global $wp_styles;
		$src = $wp_styles->registered['universal-telegram-chat-widget']->src;

		$this->assertStringContainsString( 'chat-widget-minimal.css', $src );
	}

	public function test_enqueue_falls_back_to_theme_for_a_corrupted_preset_value(): void {
		update_option( Settings::OPTION_NAME, array_merge( ( new Settings() )->defaults(), array( 'chat_widget_enabled' => true ) ) );
		update_option( Settings::OPTION_NAME, array_merge( get_option( Settings::OPTION_NAME ), array( 'chat_widget_preset' => 'not-a-real-preset' ) ) );
		$this->make_eligible_destination();

		$this->go_to( home_url( '/' ) );
		$assets = $this->assets();
		$assets->enqueue();

		global $wp_styles;
		$src = $wp_styles->registered['universal-telegram-chat-widget']->src;

		$this->assertStringContainsString( 'chat-widget-theme.css', $src );
	}

	public function test_enqueue_selects_the_theme_stylesheet_by_default(): void {
		update_option( Settings::OPTION_NAME, array_merge( ( new Settings() )->defaults(), array( 'chat_widget_enabled' => true ) ) );
		$this->make_eligible_destination();

		$this->go_to( home_url( '/' ) );
		$assets = $this->assets();
		$assets->enqueue();

		global $wp_styles;
		$src = $wp_styles->registered['universal-telegram-chat-widget']->src;

		$this->assertStringContainsString( 'chat-widget-theme.css', $src );
	}

	public function test_config_island_reflects_logged_out_state_with_no_nonce(): void {
		update_option( Settings::OPTION_NAME, array_merge( ( new Settings() )->defaults(), array( 'chat_widget_enabled' => true ) ) );
		$this->make_eligible_destination();
		wp_set_current_user( 0 );

		$this->go_to( home_url( '/' ) );
		$assets = $this->assets();

		ob_start();
		$assets->print_config();
		$output = ob_get_clean();

		$this->assertStringContainsString( '"loggedIn":false', $output );
		$this->assertStringContainsString( '"nonce":null', $output );
		$this->assertStringContainsString( 'loginUrl', $output );
	}

	public function test_config_island_reflects_logged_in_state_with_a_nonce(): void {
		update_option( Settings::OPTION_NAME, array_merge( ( new Settings() )->defaults(), array( 'chat_widget_enabled' => true ) ) );
		$this->make_eligible_destination();
		wp_set_current_user( self::factory()->user->create() );

		$this->go_to( home_url( '/' ) );
		$assets = $this->assets();

		ob_start();
		$assets->print_config();
		$output = ob_get_clean();

		$this->assertStringContainsString( '"loggedIn":true', $output );
		$this->assertStringNotContainsString( '"nonce":null', $output );
	}

	public function test_config_island_omits_registration_link_when_registration_is_disabled(): void {
		update_option( Settings::OPTION_NAME, array_merge( ( new Settings() )->defaults(), array( 'chat_widget_enabled' => true ) ) );
		update_option( 'users_can_register', 0 );
		$this->make_eligible_destination();

		$this->go_to( home_url( '/' ) );
		$assets = $this->assets();

		ob_start();
		$assets->print_config();
		$output = ob_get_clean();

		$this->assertStringContainsString( '"registerUrl":null', $output );
	}

	public function test_config_island_includes_appearance_and_label_fields_with_no_visitor_specific_data(): void {
		update_option(
			Settings::OPTION_NAME,
			array_merge(
				( new Settings() )->defaults(),
				array(
					'chat_widget_enabled'        => true,
					'chat_widget_geometry'       => 'square',
					'chat_widget_motion_default' => 'reduced',
					'chat_widget_participant_label_visitor' => 'Me',
					'chat_widget_participant_label_operator' => 'Team',
				)
			)
		);
		$this->make_eligible_destination();

		$this->go_to( home_url( '/' ) );
		$assets = $this->assets();

		ob_start();
		$assets->print_config();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'square', $output );
		$this->assertStringContainsString( 'reduced', $output );
		$this->assertStringContainsString( 'Me', $output );
		$this->assertStringContainsString( 'Team', $output );
		$this->assertStringNotContainsString( 'conversation_uuid', $output );
		$this->assertStringNotContainsString( 'secret', $output );
	}

	// A REST_REQUEST-exclusion test is deliberately not repeated here:
	// REST_REQUEST is a real PHP constant that cannot be undefined once
	// set, and TrackerAssetsTest already defines it in its own last-run
	// test (tests/integration/Events/Visitor/TrackerAssetsTest.php) to
	// exercise the identical nine-condition should_enqueue() gate this
	// class shares. Defining it again here would leak across test files
	// within the same PHPUnit process, since "ChatWidget" sorts before
	// "Events" and would poison every test that runs after it.
}
