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
 * M08.2 plan §7 WP5: the "Test notifications" tab replacing the developer
 * Simulator — GET selection only carries catalog keys, every example
 * value and the test action itself is a nonce-protected POST that renders
 * its result inline in the same response (M08.2 plan §3/§6).
 */
final class NotificationTesterPageTest extends WP_UnitTestCase {

	private const EVENT_TYPE = 'wordpress.user_registered';

	protected function tearDown(): void {
		unset( $_GET['mode'], $_GET['rule_id'], $_GET['event_type'], $_POST['mode'], $_POST['rule_id'], $_POST['event_type'], $_POST['values'], $_POST['_wpnonce'], $_SERVER['REQUEST_METHOD'] );
		parent::tearDown();
	}

	protected function setUp(): void {
		parent::setUp();

		( new CapabilityRegistrar() )->grant_to_administrator();
	}

	private function rules(): NotificationRuleRepository {
		return new NotificationRuleRepository( new SchemaHealth(), Plugin::instance()->event_registry() );
	}

	private function page( NotificationRuleRepository $rules ): NotificationTesterPage {
		$registry     = Plugin::instance()->event_registry();
		$dispatch_log = $this->createMock( DispatchLogRepository::class );
		$dispatcher   = $this->createMock( NotificationDispatcher::class );
		$dispatcher->expects( $this->never() )->method( 'dispatch' );

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

	public function test_capability_gate_blocks_a_user_without_manage_automations(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->expectException( \WPDieException::class );
		$this->page( $this->rules() )->render_tab_content();
	}

	public function test_the_page_shows_the_safety_intro_and_never_shows_raw_technical_identifiers(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$rules = $this->rules();
		$rule  = $this->eligible_rule( $rules );
		$_GET['rule_id'] = (string) $rule->id();

		ob_start();
		$this->page( $rules )->render_tab_content();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Test your notification setup safely. No Telegram message is sent.', $html );
		$this->assertStringContainsString( 'New user registered', $html );
		$this->assertStringNotContainsString( self::EVENT_TYPE, $html );
		$this->assertStringNotContainsString( '{{', $html );
		$this->assertStringNotContainsString( '}}', $html );
	}

	public function test_a_get_request_never_runs_a_test_only_a_verified_post_does(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$rules = $this->rules();
		$rule  = $this->eligible_rule( $rules );
		$_GET['rule_id'] = (string) $rule->id();

		ob_start();
		$this->page( $rules )->render_tab_content();
		$html = ob_get_clean();

		$this->assertStringNotContainsString( 'id="ut-tester-result"', $html );
	}

	/**
	 * Regression test: the "Example values" form's own POST action URL
	 * must repeat mode/rule_id (or event_type) as query params, not only
	 * as hidden POST fields — the page resolves which rule/event is
	 * selected from $_GET, not $_POST, so a form missing them would land
	 * back on an empty picker with no visible result after submitting,
	 * looking like a silent page refresh.
	 */
	public function test_the_example_values_forms_action_url_carries_mode_and_rule_id_so_submitting_does_not_lose_the_selection(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$rules = $this->rules();
		$rule  = $this->eligible_rule( $rules );
		$_GET['rule_id'] = (string) $rule->id();

		ob_start();
		$this->page( $rules )->render_tab_content();
		$html = ob_get_clean();

		$this->assertMatchesRegularExpression( '/<form method="post" action="[^"]*mode=rule[^"]*"/', $html );
		$this->assertMatchesRegularExpression( '/<form method="post" action="[^"]*rule_id=' . $rule->id() . '[^"]*"/', $html );
	}

	public function test_a_post_without_a_valid_nonce_is_rejected_and_runs_no_test(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$rules = $this->rules();
		$rule  = $this->eligible_rule( $rules );

		$_GET['rule_id']            = (string) $rule->id();
		$_SERVER['REQUEST_METHOD']  = 'POST';
		$_POST['mode']              = 'rule';
		$_POST['rule_id']           = (string) $rule->id();
		$_POST['_wpnonce']          = 'not-a-real-nonce';
		$_POST['values']            = array( 'subject.username' => 'jsmith' );

		$this->expectException( \WPDieException::class );
		$this->page( $rules )->render_tab_content();
	}

	public function test_a_post_with_a_valid_nonce_renders_the_result_inline_never_over_get(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
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

		$this->assertStringContainsString( 'id="ut-tester-result"', $html );
		$this->assertStringContainsString( 'This notification would be sent.', $html );
	}

	public function test_custom_scenario_mode_shows_an_empty_state_when_no_rules_exist_for_the_event(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$rules = $this->rules();

		$_GET['mode']               = 'event';
		$_GET['event_type']         = self::EVENT_TYPE;
		$_SERVER['REQUEST_METHOD']  = 'POST';
		$_POST['mode']              = 'event';
		$_POST['event_type']        = self::EVENT_TYPE;
		$_POST['_wpnonce']          = wp_create_nonce( NotificationTesterPage::NONCE_ACTION );
		$_POST['values']            = array();

		ob_start();
		$this->page( $rules )->render_tab_content();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'No notification rules are currently set up for this event.', $html );
	}
}
