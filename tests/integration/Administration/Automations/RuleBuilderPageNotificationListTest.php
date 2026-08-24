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
use UniversalTelegram\Telegram\Configuration\DestinationKind;
use WP_UnitTestCase;

/**
 * "Your active notifications" is a compact Name/When/Destination/Status
 * list — priority and cooldown are deliberately absent (reachable only via
 * Edit's own "Advanced delivery options" disclosure), and "When" always
 * shows the friendly event label, never a raw event_type such as
 * `woocommerce.cart_item_added` (UI-polish follow-up to M08.1).
 */
final class RuleBuilderPageNotificationListTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();

		( new CapabilityRegistrar() )->grant_to_administrator();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	private function rules(): NotificationRuleRepository {
		return new NotificationRuleRepository( new SchemaHealth(), Plugin::instance()->event_registry() );
	}

	private function page( NotificationRuleRepository $rules ): RuleBuilderPage {
		return new RuleBuilderPage(
			$rules,
			Plugin::instance()->event_registry(),
			Plugin::instance()->bot_profile_repository(),
			Plugin::instance()->destination_repository()
		);
	}

	public function test_active_notification_row_shows_friendly_when_and_destination_never_raw_identifiers(): void {
		$bot = Plugin::instance()->bot_profile_repository()->create( 'Shop bot', str_repeat( 'a', 46 ) );
		$this->assertNotNull( $bot );

		$destination = Plugin::instance()->destination_repository()->create( $bot->id(), DestinationKind::GROUP, '-100123', null, 'Sales channel' );
		$this->assertNotNull( $destination );

		$rules = $this->rules();
		$rules->save( null, 'Cart notice', 'woocommerce.cart_item_added', 1, array(), $bot->id(), $destination->id(), 'x', true, 100, 0, 'all' );

		ob_start();
		$this->page( $rules )->render_tab_content();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Cart notice', $html );
		$this->assertStringContainsString( 'Product added to cart', $html );
		$this->assertStringNotContainsString( 'woocommerce.cart_item_added', $html );
		$this->assertStringContainsString( 'Shop bot / Sales channel', $html );
		$this->assertStringContainsString( 'ut-status-pill is-active', $html );
		$this->assertStringContainsString( '>Active<', $html );

		// Priority and cooldown are not part of the compact list.
		$this->assertStringNotContainsString( 'Cooldown (s)', $html );
		$this->assertStringNotContainsString( '<th></th>', $html );
	}

	public function test_a_disabled_rule_shows_the_disabled_status_pill(): void {
		$bot         = Plugin::instance()->bot_profile_repository()->create( 'Bot', str_repeat( 'b', 46 ) );
		$destination = Plugin::instance()->destination_repository()->create( $bot->id(), DestinationKind::GROUP, '-100999', null, 'Ops' );

		$rules = $this->rules();
		$rules->save( null, 'Draft rule', 'wordpress.user_registered', 1, array(), $bot->id(), $destination->id(), 'x', false, 100, 0, 'all' );

		ob_start();
		$this->page( $rules )->render_tab_content();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'ut-status-pill is-disabled', $html );
		$this->assertStringContainsString( '>Disabled<', $html );
	}

	public function test_a_rule_with_a_deleted_bot_shows_a_safe_fallback_destination_label(): void {
		$rules = $this->rules();
		$rules->save( null, 'Orphaned rule', 'wordpress.user_registered', 1, array(), 999999, 999999, 'x', true, 100, 0, 'all' );

		ob_start();
		$this->page( $rules )->render_tab_content();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Unknown destination', $html );
	}

	public function test_row_menu_contains_delete_and_edit_stays_directly_visible(): void {
		$bot         = Plugin::instance()->bot_profile_repository()->create( 'Bot', str_repeat( 'c', 46 ) );
		$destination = Plugin::instance()->destination_repository()->create( $bot->id(), DestinationKind::GROUP, '-100777', null, 'Ops' );

		$rules = $this->rules();
		$saved = $rules->save( null, 'A rule', 'wordpress.user_registered', 1, array(), $bot->id(), $destination->id(), 'x', true, 100, 0, 'all' );

		ob_start();
		$this->page( $rules )->render_tab_content();
		$html = ob_get_clean();

		$this->assertStringContainsString( '>Edit<', $html );
		$this->assertStringContainsString( 'class="ut-row-menu"', $html );
		$this->assertStringContainsString( 'value="delete_rule"', $html );
	}
}
