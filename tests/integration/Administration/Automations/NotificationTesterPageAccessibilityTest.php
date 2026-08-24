<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Administration\Automations;

use UniversalTelegram\Administration\Automations\NotificationTester;
use UniversalTelegram\Administration\Automations\NotificationTesterPage;
use UniversalTelegram\Administration\Automations\PreviewRenderer;
use UniversalTelegram\Automations\DispatchLogRepository;
use UniversalTelegram\Automations\NotificationDispatcher;
use UniversalTelegram\Automations\NotificationRule;
use UniversalTelegram\Automations\NotificationRuleRepository;
use UniversalTelegram\Automations\RuleEvaluator;
use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;
use UniversalTelegram\Core\Plugin;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Telegram\Configuration\BotStatus;
use UniversalTelegram\Telegram\Configuration\DestinationKind;
use WP_UnitTestCase;

/**
 * M08.2 plan §7 WP6: labels, fieldsets, keyboard operation, and
 * colour-independent result status for the Test notifications page,
 * mirroring RuleBuilderPageAccessibilityTest's own assertion shape.
 */
final class NotificationTesterPageAccessibilityTest extends WP_UnitTestCase {

	private const EVENT_TYPE = 'wordpress.user_registered';

	protected function tearDown(): void {
		unset( $_GET['mode'], $_GET['rule_id'], $_POST['mode'], $_POST['rule_id'], $_POST['values'], $_POST['_wpnonce'], $_SERVER['REQUEST_METHOD'] );
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

	private function page( NotificationRuleRepository $rules ): NotificationTesterPage {
		$registry     = Plugin::instance()->event_registry();
		$dispatch_log = $this->createMock( DispatchLogRepository::class );
		$dispatcher   = $this->createMock( NotificationDispatcher::class );

		$tester = new NotificationTester(
			new RuleEvaluator( $rules, $registry, $dispatch_log, $dispatcher ),
			$rules,
			Plugin::instance()->bot_profile_repository(),
			Plugin::instance()->destination_repository(),
			$registry,
			new PreviewRenderer( $registry )
		);

		return new NotificationTesterPage(
			$tester,
			$rules,
			$registry,
			Plugin::instance()->bot_profile_repository(),
			Plugin::instance()->destination_repository()
		);
	}

	private function eligible_rule( NotificationRuleRepository $rules ): NotificationRule {
		$bot = Plugin::instance()->bot_profile_repository()->create( 'Bot', str_repeat( 'a', 46 ) );
		Plugin::instance()->bot_profile_repository()->set_status( $bot->id(), BotStatus::ACTIVE );
		$destination = Plugin::instance()->destination_repository()->create( $bot->id(), DestinationKind::GROUP, '-100123', null, 'Ops' );

		return $rules->save( null, 'Welcome message', self::EVENT_TYPE, 1, array(), $bot->id(), $destination->id(), 'Welcome {{subject.username}}', true, 100, 0, 'all' );
	}

	public function test_the_mode_switch_is_a_real_fieldset_with_a_legend(): void {
		ob_start();
		$this->page( $this->rules() )->render_tab_content();
		$html = ob_get_clean();

		$this->assertMatchesRegularExpression( '/<fieldset>\s*<legend[^>]*>Test mode<\/legend>/', $html );
	}

	public function test_the_rule_picker_select_has_a_bound_label(): void {
		ob_start();
		$this->page( $this->rules() )->render_tab_content();
		$html = ob_get_clean();

		$this->assertStringContainsString( '<label for="ut-tester-rule">', $html );
		$this->assertStringContainsString( 'id="ut-tester-rule"', $html );
	}

	public function test_the_example_values_section_is_a_fieldset_with_a_legend_and_labelled_inputs(): void {
		$rules = $this->rules();
		$rule  = $this->eligible_rule( $rules );
		$_GET['rule_id'] = (string) $rule->id();

		ob_start();
		$this->page( $rules )->render_tab_content();
		$html = ob_get_clean();

		$this->assertStringContainsString( '<fieldset><legend>Example values</legend>', $html );
		$this->assertMatchesRegularExpression( '/<label for="ut-tester-value-[^"]+">/', $html );
	}

	public function test_the_result_region_is_a_single_aria_live_container(): void {
		$rules = $this->rules();
		$rule  = $this->eligible_rule( $rules );

		$_GET['rule_id']           = (string) $rule->id();
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST['mode']             = 'rule';
		$_POST['rule_id']          = (string) $rule->id();
		$_POST['_wpnonce']         = wp_create_nonce( NotificationTesterPage::NONCE_ACTION );
		$_POST['values']           = array( 'subject.username' => 'jsmith' );

		ob_start();
		$this->page( $rules )->render_tab_content();
		$html = ob_get_clean();

		$this->assertMatchesRegularExpression( '/<div id="ut-tester-result"[^>]*aria-live="polite"/', $html );
	}

	public function test_the_result_status_pairs_an_icon_with_plain_text_never_colour_alone(): void {
		$rules = $this->rules();
		$rule  = $this->eligible_rule( $rules );
		// A condition that the submitted example value deliberately fails,
		// so this rule's own outcome is genuinely NOT_MATCHED rather than
		// the always-true empty-condition default.
		$rules->save( $rule->id(), $rule->name(), $rule->event_type(), 1, array( array( 'field' => 'subject.username', 'operator' => 'equals', 'value' => 'jsmith' ) ), $rule->bot_id(), $rule->destination_id(), $rule->template(), true, 100, 0, 'all' );

		$_GET['rule_id']           = (string) $rule->id();
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST['mode']             = 'rule';
		$_POST['rule_id']          = (string) $rule->id();
		$_POST['_wpnonce']         = wp_create_nonce( NotificationTesterPage::NONCE_ACTION );
		$_POST['values']           = array( 'subject.username' => 'not-a-match' );

		ob_start();
		$this->page( $rules )->render_tab_content();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'dashicons-no', $html );
		$this->assertStringContainsString( 'This notification would not be sent.', $html );
	}
}
