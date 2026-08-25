<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Administration\Hub;

use UniversalTelegram\Administration\Automations\EventCatalogPage;
use UniversalTelegram\Administration\Automations\EventHistoryPage;
use UniversalTelegram\Administration\Automations\RuleBuilderPage;
use UniversalTelegram\Administration\Automations\NotificationTesterPage;
use UniversalTelegram\Administration\Diagnostics\DiagnosticsPage;
use UniversalTelegram\Administration\Hub\LegacyUrlRedirector;
use UniversalTelegram\Administration\Telegram\BotManagementPage;
use UniversalTelegram\Administration\Visitor\VisitorTrackingPage;
use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;
use WP_UnitTestCase;

final class LegacyUrlRedirectorTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		( new CapabilityRegistrar() )->grant_to_administrator();
		$_SERVER['REQUEST_METHOD'] = 'GET';
	}

	protected function tearDown(): void {
		unset( $_SERVER['REQUEST_METHOD'] );
		parent::tearDown();
	}

	/**
	 * @return array<int, array{0: string, 1: string}> old slug => expected tab id.
	 */
	public static function slug_provider(): array {
		return array(
			array( DiagnosticsPage::SLUG, 'diagnostics' ),
			array( BotManagementPage::SLUG, 'bots' ),
			array( EventCatalogPage::SLUG, 'events' ),
			array( RuleBuilderPage::SLUG, 'rules' ),
			array( NotificationTesterPage::SLUG, 'test-notifications' ),
			array( EventHistoryPage::SLUG, 'event-history' ),
			array( VisitorTrackingPage::SLUG, 'visitor-tracking' ),
		);
	}

	private function capturing_redirector(): LegacyUrlRedirector {
		return new class() extends LegacyUrlRedirector {
			public ?string $captured_url = null;

			protected function redirect_and_exit( string $url ): void {
				$this->captured_url = $url;
			}
		};
	}

	/**
	 * @dataProvider slug_provider
	 */
	public function test_a_get_request_to_each_legacy_slug_redirects_to_its_tab( string $old_slug, string $expected_tab_id ): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$redirector = $this->capturing_redirector();
		$redirector->redirect( $old_slug );

		$this->assertNotNull( $redirector->captured_url );
		$this->assertStringContainsString( 'page=universal-telegram', $redirector->captured_url );
		$this->assertStringContainsString( 'tab=' . $expected_tab_id, $redirector->captured_url );
	}

	public function test_a_bookmark_with_no_other_query_args_still_resolves_to_the_right_tab(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$redirector = $this->capturing_redirector();
		$redirector->redirect( BotManagementPage::SLUG );

		$this->assertStringContainsString( 'tab=bots', $redirector->captured_url );
	}

	public function test_a_capability_denied_user_is_denied_before_any_redirect_is_computed(): void {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$redirector = $this->capturing_redirector();

		$this->expectException( \WPDieException::class );
		$redirector->redirect( BotManagementPage::SLUG );
	}

	public function test_a_capability_denied_user_never_triggers_a_redirect(): void {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$redirector = $this->capturing_redirector();

		try {
			$redirector->redirect( BotManagementPage::SLUG );
		} catch ( \WPDieException $exception ) {
			$this->assertNotNull( $exception, 'Expected capability denial before any redirect target was computed.' );
		}

		$this->assertNull( $redirector->captured_url );
	}

	public function test_a_non_get_request_never_triggers_a_redirect(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		$_SERVER['REQUEST_METHOD'] = 'POST';

		$redirector = $this->capturing_redirector();

		ob_start();
		$redirector->redirect( BotManagementPage::SLUG );
		$output = ob_get_clean();

		$this->assertNull( $redirector->captured_url );
		$this->assertStringContainsString( 'moved', $output );
	}
}
