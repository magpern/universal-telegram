<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Administration;

use UniversalTelegram\Administration\Hub\HubPage;
use UniversalTelegram\Administration\Hub\SettingsPage;
use UniversalTelegram\Administration\PluginActionLinks;
use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;
use WP_UnitTestCase;

final class PluginActionLinksTest extends WP_UnitTestCase {

	public function test_a_capable_user_sees_the_settings_link_pointing_at_the_settings_tab(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		( new CapabilityRegistrar() )->grant_to_administrator();
		wp_set_current_user( $admin );

		$links = ( new PluginActionLinks( 'universal-telegram/universal-telegram.php' ) )->add_settings_link( array() );

		$this->assertCount( 1, $links );
		$this->assertStringContainsString( 'page=' . HubPage::SLUG, $links[0] );
		$this->assertStringContainsString( 'tab=' . SettingsPage::TAB_ID, $links[0] );
		$this->assertStringContainsString( 'Settings', $links[0] );
	}

	public function test_a_user_without_the_capability_never_sees_the_link(): void {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$links = ( new PluginActionLinks( 'universal-telegram/universal-telegram.php' ) )->add_settings_link( array( '<a href="#">Existing</a>' ) );

		$this->assertCount( 1, $links );
		$this->assertSame( '<a href="#">Existing</a>', $links[0] );
	}
}
