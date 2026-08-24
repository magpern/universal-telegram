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
 * The Notifications landing page shows only three popular templates and the
 * Store-essentials panel up front; the rest of the catalog lives behind
 * per-family accordions, and starting from any of them only ever reaches
 * the builder pre-filled — nothing is created or enabled just by landing on
 * this page (UI-polish follow-up to the M08.1 plan's "Presets are starting
 * configurations only").
 */
final class RuleBuilderPagePresetsTest extends WP_UnitTestCase {

	protected function tearDown(): void {
		unset( $_GET['view'], $_GET['error'], $_GET['preset'] );
		parent::tearDown();
	}

	protected function setUp(): void {
		parent::setUp();

		( new CapabilityRegistrar() )->grant_to_administrator();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * WooCommerceSupport is deliberately final and its own is_active() is a
	 * pure, unfakeable function of the real environment
	 * (class_exists('WooCommerce')) — never mocked. Tests requiring it
	 * active/inactive are guarded with UT_TEST_WC_ACTIVE below, matching
	 * this suite's other WooCommerce-conditional tests.
	 */
	private function page(): RuleBuilderPage {
		return new RuleBuilderPage(
			Plugin::instance()->notification_rule_repository(),
			Plugin::instance()->event_registry(),
			Plugin::instance()->bot_profile_repository(),
			Plugin::instance()->destination_repository(),
			null,
			null,
			null,
			null,
			new WooCommerceSupport()
		);
	}

	public function test_landing_page_shows_popular_templates_starter_set_panel_and_custom_option(): void {
		if ( ! getenv( 'UT_TEST_WC_ACTIVE' ) ) {
			$this->markTestSkipped( 'WooCommerce is not active in this configuration.' );
		}

		ob_start();
		$this->page()->render_tab_content();
		$html = ob_get_clean();

		$this->assertStringContainsString( '>Notifications<', $html );
		$this->assertStringContainsString( 'Create custom notification', $html );

		// The three WooCommerce popular templates, as compact tiles.
		$this->assertStringContainsString( 'ut-template-tile', $html );
		$this->assertStringContainsString( 'New WooCommerce order', $html );
		$this->assertStringContainsString( 'Order failed', $html );
		$this->assertStringContainsString( 'Low-stock alert', $html );

		// The Store-essentials panel is present and explains itself.
		$this->assertStringContainsString( 'ut-starter-set-panel', $html );
		$this->assertStringContainsString( 'Store essentials', $html );
		$this->assertStringContainsString( 'Set up starter set', $html );
		$this->assertStringContainsString( 'created as reviewable drafts', $html );

		// The remaining templates live behind collapsed accordions, not as
		// equally-weighted cards on the same screen.
		$this->assertStringContainsString( 'ut-template-family', $html );
		$this->assertStringContainsString( '<details', $html );

		// Regression: links must resolve to the real registered Hub page
		// (HubPage::SLUG, "universal-telegram"), never RuleBuilderPage::SLUG
		// (the retired pre-Hub slug, "universal-telegram-rules") — a link
		// built from the retired slug is silently caught by
		// LegacyUrlRedirector with every extra query arg dropped.
		$this->assertStringContainsString( 'page=universal-telegram&#038;tab=rules&#038;view=starter_set', $html );
		$this->assertStringContainsString( 'page=universal-telegram&#038;tab=rules&#038;view=create', $html );
		$this->assertStringNotContainsString( 'page=universal-telegram-rules', $html );

		// Rendering the landing page must never itself write a rule.
		$this->assertCount( 0, Plugin::instance()->notification_rule_repository()->all() );
	}

	public function test_woocommerce_only_content_is_hidden_and_replaced_when_inactive(): void {
		if ( getenv( 'UT_TEST_WC_ACTIVE' ) ) {
			$this->markTestSkipped( 'This assertion applies only to the WooCommerce-absent configuration.' );
		}

		ob_start();
		$this->page()->render_tab_content();
		$html = ob_get_clean();

		$this->assertStringNotContainsString( 'New WooCommerce order', $html );
		// The static <style> block always defines the .ut-starter-set-panel
		// CSS rule regardless of WooCommerce state, so the check below is
		// against the actual rendered element, not the bare class name.
		$this->assertStringNotContainsString( 'class="ut-starter-set-panel"', $html );

		// A non-WooCommerce popular set still appears — the section is
		// never simply empty.
		$this->assertStringContainsString( 'Failed login attempt', $html );
	}

	public function test_use_template_link_opens_the_builder_prefilled_without_creating_anything(): void {
		if ( ! getenv( 'UT_TEST_WC_ACTIVE' ) ) {
			$this->markTestSkipped( 'WooCommerce is not active in this configuration.' );
		}

		$_GET['view']   = 'create';
		$_GET['preset'] = 'low_stock';

		ob_start();
		$this->page()->render_tab_content();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Create notification', $html );
		$this->assertStringContainsString( 'value="Low-stock alert"', $html );
		$this->assertStringContainsString( 'Back to notifications', $html );
		$this->assertCount( 0, Plugin::instance()->notification_rule_repository()->all() );
	}

	public function test_starter_set_review_screen_shows_all_three_rules_and_a_single_destination_pair(): void {
		if ( ! getenv( 'UT_TEST_WC_ACTIVE' ) ) {
			$this->markTestSkipped( 'WooCommerce is not active in this configuration.' );
		}

		$_GET['view'] = 'starter_set';

		ob_start();
		$this->page()->render_tab_content();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'New WooCommerce order', $html );
		$this->assertStringContainsString( 'Order failed', $html );
		$this->assertStringContainsString( 'Low-stock alert', $html );
		$this->assertStringContainsString( 'id="ut-starter-bot"', $html );
		$this->assertStringContainsString( 'id="ut-starter-destination"', $html );
		$this->assertStringContainsString( 'Create draft rules', $html );
		$this->assertStringContainsString( 'Back to notifications', $html );

		// The review screen itself is a GET render; it must never create a rule.
		$this->assertCount( 0, Plugin::instance()->notification_rule_repository()->all() );
	}

	public function test_starter_set_review_screen_shows_the_error_notice_when_flagged(): void {
		if ( ! getenv( 'UT_TEST_WC_ACTIVE' ) ) {
			$this->markTestSkipped( 'WooCommerce is not active in this configuration.' );
		}

		$_GET['view']  = 'starter_set';
		$_GET['error'] = 'missing_destination';

		ob_start();
		$this->page()->render_tab_content();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Choose a bot and destination before creating the draft rules.', $html );
	}
}
