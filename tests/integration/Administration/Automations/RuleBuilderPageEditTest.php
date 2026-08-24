<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Administration\Automations;

use UniversalTelegram\Administration\Automations\RuleBuilderPage;
use UniversalTelegram\Automations\NotificationRuleRepository;
use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;
use UniversalTelegram\Core\Plugin;
use UniversalTelegram\Persistence\SchemaHealth;
use WP_UnitTestCase;

final class RuleBuilderPageEditTest extends WP_UnitTestCase {

	protected function tearDown(): void {
		unset( $_GET['edit'] );
		parent::tearDown();
	}

	protected function setUp(): void {
		parent::setUp();

		( new CapabilityRegistrar() )->grant_to_administrator();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	private function rules(): NotificationRuleRepository {
		return new NotificationRuleRepository( new SchemaHealth(), Plugin::instance()->event_registry() );
	}

	private function page(): RuleBuilderPage {
		return new RuleBuilderPage(
			$this->rules(),
			Plugin::instance()->event_registry(),
			Plugin::instance()->bot_profile_repository(),
			Plugin::instance()->destination_repository()
		);
	}

	/**
	 * Regression: the rule list's "Edit" action must link to the real
	 * registered Hub page (HubPage::SLUG), never RuleBuilderPage::SLUG (the
	 * retired pre-Hub slug) — a link built from the retired slug is
	 * silently caught by LegacyUrlRedirector and redirected with the
	 * edit=<id> query arg dropped, landing back on a blank Add Rule form.
	 */
	public function test_the_edit_link_points_to_the_real_hub_page_slug(): void {
		$rules = $this->rules();
		$saved = $rules->save( null, 'A rule', 'wordpress.user_registered', 1, array(), 1, 1, 'x', true, 100, 0, 'all' );

		ob_start();
		$this->page()->render_tab_content();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'page=universal-telegram&#038;tab=rules&#038;edit=' . $saved->id(), $html );
		$this->assertStringNotContainsString( 'page=universal-telegram-rules', $html );
	}

	public function test_editing_a_representable_rule_prefills_the_visible_condition_row(): void {
		$rules = $this->rules();
		$saved = $rules->save(
			null,
			'Failed login rule',
			'wordpress.login_failed',
			1,
			array(
				array(
					'field'    => 'context.username',
					'operator' => 'equals',
					'value'    => 'admin',
				),
			),
			1,
			1,
			'x',
			true,
			100,
			0,
			'all'
		);

		$_GET['edit'] = (string) $saved->id();

		ob_start();
		$this->page()->render_tab_content();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Edit rule', $html );
		$this->assertStringContainsString( 'id="ut-conditions-wrap"', $html );
		$this->assertStringNotContainsString( 'style="display:none"', substr( $html, (int) strpos( $html, 'id="ut-conditions-wrap"' ), 60 ) );
		$this->assertStringContainsString( 'value="admin"', $html );
		$this->assertStringNotContainsString( 'This rule\'s conditions were created with a format', $html );
	}

	public function test_editing_an_unrepresentable_rule_shows_the_read_only_fallback_and_no_json_textarea(): void {
		$rules = $this->rules();

		// 'greater_than' is a valid ConditionOperator (so
		// NotificationRuleRepository::save()'s own field-allowlist check
		// accepts it) but is not one of context.username's own permitted
		// friendly operators for a text field (equals/not_equals/contains/
		// not_contains) — exactly the "engine-valid, builder-unrepresentable"
		// case the compatibility strategy exists for.
		$saved = $rules->save(
			null,
			'Legacy rule',
			'wordpress.login_failed',
			1,
			array(
				array(
					'field'    => 'context.username',
					'operator' => 'greater_than',
					'value'    => 'adm',
				),
			),
			1,
			1,
			'x',
			true,
			100,
			0,
			'all'
		);

		$_GET['edit'] = (string) $saved->id();

		ob_start();
		$this->page()->render_tab_content();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'This rule\'s conditions were created with a format the visual builder cannot display', $html );
		$this->assertStringContainsString( 'greater_than', $html );
		$this->assertStringContainsString( 'name="conditions_locked" value="1"', $html );
		$this->assertStringNotContainsString( '<textarea id="ut-rule-conditions"', $html );
		$this->assertStringNotContainsString( 'id="ut-add-condition"', $html );
	}

	public function test_editing_an_unknown_rule_id_falls_back_to_the_normal_add_rule_form(): void {
		$_GET['edit'] = '999999';

		ob_start();
		$this->page()->render_tab_content();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Add rule', $html );
	}
}
