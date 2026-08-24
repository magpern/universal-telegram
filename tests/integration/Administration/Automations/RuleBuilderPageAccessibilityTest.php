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
 * WP7 polish: advanced delivery options collapsed by default, the plain-
 * language cooldown label, and the accessible save-error summary
 * (M08.1 plan "Accessibility and admin integration", "Delivery options").
 */
final class RuleBuilderPageAccessibilityTest extends WP_UnitTestCase {

	protected function tearDown(): void {
		unset( $_GET['save_error'], $_GET['view'] );
		parent::tearDown();
	}

	protected function setUp(): void {
		parent::setUp();

		( new CapabilityRegistrar() )->grant_to_administrator();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	private function page(): RuleBuilderPage {
		return new RuleBuilderPage(
			Plugin::instance()->notification_rule_repository(),
			Plugin::instance()->event_registry(),
			Plugin::instance()->bot_profile_repository(),
			Plugin::instance()->destination_repository()
		);
	}

	public function test_priority_and_cooldown_are_collapsed_behind_advanced_delivery_options(): void {
		$_GET['view'] = 'create';

		ob_start();
		$this->page()->render_tab_content();
		$html = ob_get_clean();

		$this->assertStringContainsString( '<details id="ut-advanced-delivery">', $html );
		$this->assertStringContainsString( 'Advanced delivery options', $html );
		$this->assertStringContainsString( 'Do not send repeated notifications more often than', $html );
		$this->assertStringContainsString( 'name="cooldown_minutes"', $html );

		// "Status" is plain-language and must stay outside the collapsed
		// disclosure — it is not a nonessential control.
		$enabled_position  = strpos( $html, 'id="ut-rule-enabled"' );
		$details_position  = strpos( $html, '<details id="ut-advanced-delivery">' );
		$this->assertNotFalse( $enabled_position );
		$this->assertNotFalse( $details_position );
		$this->assertLessThan( $details_position, $enabled_position );
	}

	public function test_save_error_flag_renders_a_focusable_accessible_notice(): void {
		$_GET['save_error'] = 'invalid_condition';

		ob_start();
		$this->page()->render_tab_content();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'id="ut-save-error"', $html );
		$this->assertStringContainsString( 'tabindex="-1"', $html );
		$this->assertStringContainsString( 'could not be saved', $html );
	}

	public function test_no_save_error_flag_renders_no_notice(): void {
		ob_start();
		$this->page()->render_tab_content();
		$html = ob_get_clean();

		$this->assertStringNotContainsString( 'id="ut-save-error"', $html );
	}
}
