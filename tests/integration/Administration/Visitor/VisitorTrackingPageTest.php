<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Administration\Visitor;

use UniversalTelegram\Administration\Visitor\VisitorTrackingPage;
use UniversalTelegram\Core\Configuration\Settings;
use WP_UnitTestCase;

final class VisitorTrackingPageTest extends WP_UnitTestCase {

	private function page(): VisitorTrackingPage {
		return new VisitorTrackingPage( new Settings() );
	}

	public function test_a_user_lacking_the_capability_is_denied_by_wordpress_itself(): void {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$this->expectException( \WPDieException::class );
		$this->page()->render_tab_content();
	}

	public function test_an_administrator_can_render_the_page(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		ob_start();
		$this->page()->render_tab_content();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Enable visitor tracking', $output );
		$this->assertStringContainsString( 'cannot be verified by the server', $output );
	}

	public function test_handle_request_denies_a_user_lacking_the_capability(): void {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$this->expectException( \WPDieException::class );
		$this->page()->handle_request();
	}
}
