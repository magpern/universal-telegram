<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Administration\Automations;

use UniversalTelegram\Administration\Automations\RuleBuilderPage;
use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;
use UniversalTelegram\Core\Plugin;
use WP_UnitTestCase;

/**
 * handle_preview_request() is a read-only render routed through the same
 * admin-post.php capability/nonce gate as every other action here, never a
 * mutation — this test asserts both the denial paths and that a successful
 * preview is built purely from FieldTypeCatalog sample values, never real
 * data (M08.1 plan "Define the preview precisely").
 */
final class RuleBuilderPagePreviewTest extends WP_UnitTestCase {

	protected function tearDown(): void {
		unset( $_POST['_wpnonce'], $_POST['event_type'], $_POST['template'] );
		parent::tearDown();
	}

	private function page(): RuleBuilderPage {
		return new RuleBuilderPage(
			Plugin::instance()->notification_rule_repository(),
			Plugin::instance()->event_registry(),
			Plugin::instance()->bot_profile_repository(),
			Plugin::instance()->destination_repository()
		);
	}

	public function test_missing_capability_is_denied(): void {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$_POST['event_type'] = 'wordpress.user_registered';
		$_POST['template']   = 'x';

		$this->expectException( \WPDieException::class );
		$this->page()->handle_preview_request();
	}

	public function test_missing_nonce_is_denied_even_with_capability(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		( new CapabilityRegistrar() )->grant_to_administrator();
		wp_set_current_user( $admin );

		unset( $_POST['_wpnonce'] );
		$_POST['event_type'] = 'wordpress.user_registered';
		$_POST['template']   = 'x';

		$this->expectException( \WPDieException::class );
		$this->page()->handle_preview_request();
	}

	public function test_a_valid_request_returns_a_preview_built_from_sample_values_only(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		( new CapabilityRegistrar() )->grant_to_administrator();
		wp_set_current_user( $admin );

		$_POST['_wpnonce']   = wp_create_nonce( RuleBuilderPage::PREVIEW_NONCE_ACTION );
		$_POST['event_type'] = 'wordpress.user_registered';
		$_POST['template']   = 'New user: account #{{subject.user_id}}';

		ob_start();
		try {
			$this->page()->handle_preview_request();
		} catch ( \WPDieException $exception ) {
			$json = json_decode( (string) ob_get_clean(), true );

			$this->assertIsArray( $json );
			$this->assertTrue( $json['success'] );
			$this->assertStringContainsString( 'account #42', $json['data']['preview'] );

			return;
		}

		ob_end_clean();
		$this->fail( 'Expected wp_send_json_success() to terminate via wp_die().' );
	}
}
