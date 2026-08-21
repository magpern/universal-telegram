<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Administration\Hub;

use UniversalTelegram\Administration\Hub\OverviewPage;
use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;
use WP_UnitTestCase;

final class OverviewPageTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();

		// The test bootstrap loads the plugin as an MU-plugin, bypassing
		// WordPress' real activation flow, so the capability Activator
		// would normally grant is never actually granted here.
		( new CapabilityRegistrar() )->grant_to_administrator();
	}

	public function test_a_user_lacking_the_capability_is_denied_by_wordpress_itself(): void {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$this->expectException( \WPDieException::class );
		( new OverviewPage() )->render_tab_content();
	}

	public function test_an_administrator_sees_links_to_every_other_tab(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		ob_start();
		( new OverviewPage() )->render_tab_content();
		$output = ob_get_clean();

		foreach ( array( 'tab=bots', 'tab=events', 'tab=rules', 'tab=simulator', 'tab=event-history', 'tab=visitor-tracking', 'tab=settings', 'tab=diagnostics' ) as $needle ) {
			$this->assertStringContainsString( $needle, $output );
		}
	}
}
