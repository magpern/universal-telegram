<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Administration\Automations;

use UniversalTelegram\Administration\Automations\RuleBuilderPage;
use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;
use UniversalTelegram\Core\Plugin;
use UniversalTelegram\Integrations\WooCommerce\WooCommerceSupport;
use WP_UnitTestCase;

/**
 * Presets are starting configurations only (M08.1 plan "Presets are
 * starting configurations only"): this test asserts the preset cards
 * render without any auto-save/auto-enable side effect, and that the
 * Store-essentials review screen requires its own explicit second
 * confirmation before anything is created.
 */
final class RuleBuilderPagePresetsTest extends WP_UnitTestCase {

	protected function tearDown(): void {
		unset( $_GET['view'], $_GET['error'] );
		parent::tearDown();
	}

	protected function setUp(): void {
		parent::setUp();

		( new CapabilityRegistrar() )->grant_to_administrator();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	private function page( bool $woocommerce_active ): RuleBuilderPage {
		$woocommerce_support = $this->createMock( WooCommerceSupport::class );
		$woocommerce_support->method( 'is_active' )->willReturn( $woocommerce_active );

		return new RuleBuilderPage(
			Plugin::instance()->notification_rule_repository(),
			Plugin::instance()->event_registry(),
			Plugin::instance()->bot_profile_repository(),
			Plugin::instance()->destination_repository(),
			null,
			null,
			null,
			null,
			$woocommerce_support
		);
	}

	public function test_preset_cards_render_with_a_custom_notification_option(): void {
		ob_start();
		$this->page( true )->render_tab_content();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'ut-preset-card', $html );
		$this->assertStringContainsString( 'New WooCommerce order', $html );
		$this->assertStringContainsString( 'Create a custom notification', $html );
		$this->assertStringContainsString( 'Store essentials starter set', $html );

		// Rendering the presets section must never itself write a rule.
		$this->assertCount( 0, Plugin::instance()->notification_rule_repository()->all() );
	}

	public function test_woocommerce_only_presets_and_starter_set_are_hidden_when_inactive(): void {
		ob_start();
		$this->page( false )->render_tab_content();
		$html = ob_get_clean();

		$this->assertStringNotContainsString( 'New WooCommerce order', $html );
		$this->assertStringNotContainsString( 'Store essentials starter set', $html );
		$this->assertStringContainsString( 'Successful administrator login', $html );
	}

	public function test_starter_set_review_screen_shows_all_three_rules_and_a_single_destination_pair(): void {
		$_GET['view'] = 'starter_set';

		ob_start();
		$this->page( true )->render_tab_content();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'New WooCommerce order', $html );
		$this->assertStringContainsString( 'Order failed', $html );
		$this->assertStringContainsString( 'Low-stock alert', $html );
		$this->assertStringContainsString( 'id="ut-starter-bot"', $html );
		$this->assertStringContainsString( 'id="ut-starter-destination"', $html );
		$this->assertStringContainsString( 'Create draft rules', $html );
		$this->assertStringContainsString( 'Back to presets', $html );

		// The review screen itself is a GET render; it must never create a rule.
		$this->assertCount( 0, Plugin::instance()->notification_rule_repository()->all() );
	}

	public function test_starter_set_review_screen_shows_the_error_notice_when_flagged(): void {
		$_GET['view']  = 'starter_set';
		$_GET['error'] = 'missing_destination';

		ob_start();
		$this->page( true )->render_tab_content();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Choose a bot and destination before creating the draft rules.', $html );
	}
}
