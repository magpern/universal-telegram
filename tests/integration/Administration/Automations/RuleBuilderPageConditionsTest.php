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
 * Asserts the friendly Add Rule form's own visible text — option/label text
 * nodes and help text — never carries a raw technical identifier, while
 * deliberately not asserting against the complete HTML source: a
 * `<option value="woocommerce.order_created">` attribute legitimately
 * carries the technical identifier for form submission (M08.1 plan
 * "raw-identifier test requirement").
 */
final class RuleBuilderPageConditionsTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();

		( new CapabilityRegistrar() )->grant_to_administrator();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		// The builder is its own view now (UI-polish follow-up to M08.1) —
		// the default landing page shows the Notifications overview, not
		// this form.
		$_GET['view'] = 'create';
	}

	protected function tearDown(): void {
		unset( $_GET['view'] );
		parent::tearDown();
	}

	/**
	 * Extracts the text content of every <option>/<label> element, i.e.
	 * exactly what a sighted or screen-reader admin would actually
	 * encounter, ignoring attribute values such as option `value="..."`.
	 *
	 * @param string $html The rendered page markup.
	 *
	 * @return string
	 */
	private function visible_option_and_label_text( string $html ): string {
		preg_match_all( '/<option[^>]*>([^<]*)<\/option>/i', $html, $option_matches );
		preg_match_all( '/<label[^>]*>([^<]*)<\/label>/i', $html, $label_matches );

		return implode( ' ', array_merge( $option_matches[1], $label_matches[1] ) );
	}

	/**
	 * WooCommerceSupport is deliberately final (the single place every
	 * later module asks whether WooCommerce is present) and its own
	 * is_active() is a pure, unfakeable function of the real environment
	 * (class_exists('WooCommerce')) — never mocked. Tests that specifically
	 * need it active/inactive are guarded with UT_TEST_WC_ACTIVE below,
	 * matching this suite's other WooCommerce-conditional tests.
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

	public function test_visible_text_uses_only_friendly_labels_not_technical_identifiers(): void {
		$page = $this->page();

		ob_start();
		$page->render_tab_content();
		$html = ob_get_clean();

		$visible_text = $this->visible_option_and_label_text( $html );

		$this->assertStringContainsString( 'Successful user login', $visible_text );
		$this->assertStringNotContainsString( 'wordpress.login_succeeded', $visible_text );
		$this->assertStringNotContainsString( 'payload.order_total', $visible_text );
		$this->assertStringNotContainsString( 'subject.post_id', $visible_text );

		// The technical identifier legitimately still exists in a
		// non-visible attribute, for form submission.
		$this->assertStringContainsString( 'value="wordpress.login_succeeded"', $html );
	}

	public function test_woocommerce_families_are_disabled_with_explanatory_text_when_inactive(): void {
		if ( getenv( 'UT_TEST_WC_ACTIVE' ) ) {
			$this->markTestSkipped( 'This assertion applies only to the WooCommerce-absent configuration.' );
		}

		$page = $this->page();

		ob_start();
		$page->render_tab_content();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Requires WooCommerce, which is not currently active on this site.', $html );
		$this->assertStringContainsString( '<optgroup label="Store orders and payments" disabled="disabled">', $html );
	}

	public function test_woocommerce_families_are_enabled_when_active(): void {
		if ( ! getenv( 'UT_TEST_WC_ACTIVE' ) ) {
			$this->markTestSkipped( 'WooCommerce is not active in this configuration.' );
		}

		$page = $this->page();

		ob_start();
		$page->render_tab_content();
		$html = ob_get_clean();

		$this->assertStringNotContainsString( 'Requires WooCommerce', $html );
		$this->assertStringContainsString( '<optgroup label="Store orders and payments">', $html );
	}

	public function test_condition_builder_starts_hidden_with_no_visible_rows(): void {
		$page = $this->page();

		ob_start();
		$page->render_tab_content();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'id="ut-conditions-wrap" style="display:none"', $html );
		$this->assertStringContainsString( 'id="ut-add-condition"', $html );
	}
}
