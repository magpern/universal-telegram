<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Administration\Automations;

use UniversalTelegram\Administration\Automations\RuleBuilderPage;
use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;
use UniversalTelegram\Core\Plugin;
use WP_Ajax_UnitTestCase;

/**
 * The preview request handler is a read-only render routed through the same
 * admin-post.php capability/nonce gate as every other action here, never a
 * mutation — this test asserts both the denial paths and that a successful
 * preview is built purely from FieldTypeCatalog sample values, never real
 * data (M08.1 plan "Define the preview precisely"). Extends
 * WP_Ajax_UnitTestCase rather than the plain WP_UnitTestCase: it is the
 * only WordPress core test base that filters wp_doing_ajax() to true and
 * overrides wp_die_ajax_handler to throw (a WPDieException subclass)
 * instead of really terminating the process, which wp_send_json_error()/
 * wp_send_json_success() otherwise require to be testable at all.
 */
final class RuleBuilderPagePreviewTest extends WP_Ajax_UnitTestCase {

	protected function tearDown(): void {
		unset( $_POST['_wpnonce'], $_REQUEST['_wpnonce'], $_POST['event_type'], $_POST['template'] );
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

		// WP_Ajax_UnitTestCase's own die handler consumes an output buffer
		// via ob_get_clean() inside wp_die() — it must find one it started
		// here, or PHPUnit flags the test as "risky" for an output-buffer
		// mismatch it did not itself cause.
		ob_start();
		$this->expectException( \WPDieException::class );
		$this->page()->handle_preview_request();
	}

	public function test_missing_nonce_is_denied_even_with_capability(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		( new CapabilityRegistrar() )->grant_to_administrator();
		wp_set_current_user( $admin );

		unset( $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );
		$_POST['event_type'] = 'wordpress.user_registered';
		$_POST['template']   = 'x';

		ob_start();
		$this->expectException( \WPDieException::class );
		$this->page()->handle_preview_request();
	}

	public function test_a_valid_request_returns_a_preview_built_from_sample_values_only(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		( new CapabilityRegistrar() )->grant_to_administrator();
		wp_set_current_user( $admin );

		$nonce = wp_create_nonce( RuleBuilderPage::PREVIEW_NONCE_ACTION );
		// check_ajax_referer() reads $_REQUEST, not $_POST — PHP populates
		// $_REQUEST from the real incoming request at process start, but a
		// test assigning $_POST directly does not retroactively update it.
		$_POST['_wpnonce']    = $nonce;
		$_REQUEST['_wpnonce'] = $nonce;
		$_POST['event_type']  = 'wordpress.user_registered';
		$_POST['template']    = 'New user: account #{{subject.user_id}}';

		ob_start();
		try {
			$this->page()->handle_preview_request();
		} catch ( \WPDieException $exception ) {
			// The die handler already consumed the buffer above via its own
			// ob_get_clean(), capturing the echoed JSON into
			// $this->_last_response — a second ob_get_clean() here would
			// find no buffer left to close.
			$json = json_decode( $this->_last_response, true );

			$this->assertIsArray( $json );
			$this->assertTrue( $json['success'] );
			// The literal "#" is MarkdownV2-escaped like any other literal
			// template text.
			$this->assertStringContainsString( 'account \\#42', $json['data']['preview'] );

			return;
		}

		ob_end_clean();
		$this->fail( 'Expected wp_send_json_success() to terminate via wp_die().' );
	}
}
