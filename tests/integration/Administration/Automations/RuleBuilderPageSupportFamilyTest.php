<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Administration\Automations;

use UniversalTelegram\Administration\Automations\NotificationTester;
use UniversalTelegram\Administration\Automations\NotificationTesterPage;
use UniversalTelegram\Administration\Automations\PreviewRenderer;
use UniversalTelegram\Administration\Automations\RuleBuilderPage;
use UniversalTelegram\Automations\DispatchLogRepository;
use UniversalTelegram\Automations\NotificationDispatcher;
use UniversalTelegram\Automations\RuleEvaluator;
use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;
use UniversalTelegram\Core\Plugin;
use UniversalTelegram\Events\Registry;
use UniversalTelegram\Integrations\FluentContactInbox\Events\ContactInboxEventEmitter;
use UniversalTelegram\Integrations\FluentContactInbox\FluentContactInboxSupport;
use WP_UnitTestCase;

/**
 * ADR-0046: the support-ticket event family is offered only while the support desk is
 * active; inactive, it is shown disabled with an explanation (like the WooCommerce families).
 */
final class RuleBuilderPageSupportFamilyTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();

		( new CapabilityRegistrar() )->grant_to_administrator();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$_GET['view'] = 'create';
	}

	protected function tearDown(): void {
		unset( $_GET['view'], $_GET['mode'], $_GET['tab'] );
		parent::tearDown();
	}

	private function registry_with_support_events(): Registry {
		$registry = new Registry();
		( new ContactInboxEventEmitter() )->register_event_types( $registry );

		return $registry;
	}

	private function rule_builder( bool $active ): RuleBuilderPage {
		return new RuleBuilderPage(
			Plugin::instance()->notification_rule_repository(),
			$this->registry_with_support_events(),
			Plugin::instance()->bot_profile_repository(),
			Plugin::instance()->destination_repository(),
			null,
			null,
			null,
			new FluentContactInboxSupport( static fn () => $active ? '2.1.0' : null )
		);
	}

	public function test_inactive_family_is_disabled_with_an_explanation_and_offers_no_selectable_events(): void {
		ob_start();
		$this->rule_builder( false )->render_tab_content();
		$html = ob_get_clean();

		$this->assertStringContainsString( '<optgroup label="Support tickets and contact requests" disabled="disabled">', $html );
		$this->assertStringContainsString( 'Requires the Fluent IMAP Support Desk plugin (2.1.0 or newer)', $html );
		$this->assertMatchesRegularExpression( '/<option value="fluent_contact_inbox\.ticket_created"[^>]*disabled="disabled"/', $html );
	}

	public function test_active_family_is_selectable_with_friendly_labels(): void {
		ob_start();
		$this->rule_builder( true )->render_tab_content();
		$html = ob_get_clean();

		$this->assertStringContainsString( '<optgroup label="Support tickets and contact requests">', $html );
		$this->assertStringNotContainsString( 'Requires the Fluent IMAP Support Desk plugin', $html );
		$this->assertStringContainsString( 'value="fluent_contact_inbox.contact_request_submitted"', $html );
		$this->assertStringContainsString( 'Contact form request submitted', $html );
		$this->assertStringContainsString( 'New support ticket created (from email)', $html );
		$this->assertStringContainsString( 'Customer replied to a support ticket', $html );
	}

	public function test_the_tester_page_disables_the_family_when_the_desk_is_inactive_and_enables_it_when_active(): void {
		$_GET['tab']  = NotificationTesterPage::TAB_ID;
		$_GET['mode'] = 'event';

		$registry = Plugin::instance()->event_registry();
		$rules    = Plugin::instance()->notification_rule_repository();
		$tester   = new NotificationTester(
			new RuleEvaluator( $rules, $registry, $this->createMock( DispatchLogRepository::class ), $this->createMock( NotificationDispatcher::class ) ),
			$rules,
			Plugin::instance()->bot_profile_repository(),
			Plugin::instance()->destination_repository(),
			$registry,
			new PreviewRenderer( $registry )
		);

		$build = static fn ( bool $active ) => new NotificationTesterPage(
			$tester,
			$rules,
			$registry,
			Plugin::instance()->bot_profile_repository(),
			Plugin::instance()->destination_repository(),
			null,
			new FluentContactInboxSupport( static fn () => $active ? '2.1.0' : null )
		);

		ob_start();
		$build( false )->render_tab_content();
		$inactive = ob_get_clean();

		ob_start();
		$build( true )->render_tab_content();
		$active = ob_get_clean();

		$this->assertStringContainsString( '<optgroup label="Support tickets and contact requests" disabled="disabled">', $inactive );
		$this->assertStringContainsString( '<optgroup label="Support tickets and contact requests">', $active );
	}
}
