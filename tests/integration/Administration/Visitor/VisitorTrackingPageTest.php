<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Administration\Visitor;

use UniversalTelegram\Administration\Visitor\VisitorTrackingPage;
use UniversalTelegram\Automations\Digest\DigestEligibility;
use UniversalTelegram\Conversations\ConversationRepository;
use UniversalTelegram\Conversations\VisitorTokenGenerator;
use UniversalTelegram\Core\Configuration\Settings;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;
use UniversalTelegram\Telegram\Configuration\BotStatus;
use UniversalTelegram\Telegram\Configuration\DestinationKind;
use UniversalTelegram\Telegram\Configuration\DestinationRepository;
use WP_UnitTestCase;

final class VisitorTrackingPageTest extends WP_UnitTestCase {

	private function bots(): BotProfileRepository {
		return new BotProfileRepository( new SchemaHealth(), new CredentialVault() );
	}

	private function destinations(): DestinationRepository {
		return new DestinationRepository( new SchemaHealth() );
	}

	private function conversations(): ConversationRepository {
		return new ConversationRepository( new SchemaHealth(), new CredentialVault(), new VisitorTokenGenerator() );
	}

	private function digest_eligibility(): DigestEligibility {
		return new DigestEligibility( new Settings(), $this->bots(), $this->destinations(), $this->conversations() );
	}

	private function page(): VisitorTrackingPage {
		return new VisitorTrackingPage( new Settings(), $this->bots(), $this->digest_eligibility() );
	}

	public function test_a_user_lacking_the_capability_is_denied_by_wordpress_itself(): void {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$this->expectException( \WPDieException::class );
		$this->page()->render_tab_content();
	}

	public function test_an_administrator_can_render_the_page(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		ob_start();
		$this->page()->render_tab_content();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Enable visitor tracking', $output );
		$this->assertStringContainsString( 'cannot be verified by the server', $output );
	}

	public function test_handle_request_denies_a_user_lacking_the_capability(): void {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$this->expectException( \WPDieException::class );
		$this->page()->handle_request();
	}

	/**
	 * @return VisitorTrackingPage&object{redirected_to: ?string}
	 */
	private function saving_page(): VisitorTrackingPage {
		return new class( new Settings(), $this->bots(), $this->digest_eligibility() ) extends VisitorTrackingPage {
			public ?string $redirected_to = null;

			protected function redirect_and_exit( string $url ): void {
				$this->redirected_to = $url;
			}
		};
	}

	public function test_unchecking_exclude_administrators_and_saving_persists_the_uncheck(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// exclude_administrators defaults to true; assert the starting
		// state so the test actually exercises turning it off, not a no-op.
		$this->assertTrue( ( new Settings() )->get()['visitor_exclude_administrators'] );

		$_POST['visitor_settings'] = array(
			'visitor_tracking_enabled' => '1',
			// visitor_exclude_administrators intentionally omitted: an
			// unchecked checkbox is never present in $_POST.
		);
		$nonce                = wp_create_nonce( VisitorTrackingPage::NONCE_ACTION );
		$_POST['_wpnonce']    = $nonce;
		$_REQUEST['_wpnonce'] = $nonce;

		$this->saving_page()->handle_request();

		$this->assertFalse( ( new Settings() )->get()['visitor_exclude_administrators'] );

		unset( $_POST['visitor_settings'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );
	}

	public function test_render_describes_sampling_percent(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		ob_start();
		$this->page()->render_tab_content();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'percentage of visitor sessions to record', $output );
	}

	/**
	 * Developer-only click tracking is not administrator-configurable
	 * (bug-fix authorization, corrective removal): neither the "Clicks"
	 * toggle nor the click-target allowlist textarea may appear on the
	 * ordinary settings page.
	 */
	public function test_render_exposes_neither_click_control(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		ob_start();
		$this->page()->render_tab_content();
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'visitor_family_clicks', $output );
		$this->assertStringNotContainsString( 'visitor_click_target_allowlist', $output );
		$this->assertStringNotContainsString( 'Clicks', $output );
		$this->assertStringNotContainsString( 'Click target allowlist', $output );
	}

	/**
	 * A crafted POST cannot enable click tracking even though the page no
	 * longer renders any control for it.
	 */
	public function test_a_crafted_post_cannot_enable_click_tracking(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$_POST['visitor_settings'] = array(
			'visitor_tracking_enabled'       => '1',
			'visitor_family_clicks'          => '1',
			'visitor_click_target_allowlist' => "hero-cta\nnav-link",
		);
		$nonce                     = wp_create_nonce( VisitorTrackingPage::NONCE_ACTION );
		$_POST['_wpnonce']         = $nonce;
		$_REQUEST['_wpnonce']      = $nonce;

		$this->saving_page()->handle_request();

		$saved = ( new Settings() )->get();
		$this->assertFalse( $saved['visitor_family_clicks'] );
		$this->assertSame( array(), $saved['visitor_click_target_allowlist'] );
		// Other submitted families are unaffected by the click-specific removal.
		$this->assertTrue( $saved['visitor_tracking_enabled'] );

		unset( $_POST['visitor_settings'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );
	}

	/**
	 * A legacy stored enabled flag (from before this fix) must not survive
	 * a normal settings save that doesn't even mention click tracking.
	 */
	public function test_a_legacy_persisted_click_setting_is_cleared_by_an_unrelated_save(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		update_option(
			Settings::OPTION_NAME,
			array_merge(
				( new Settings() )->defaults(),
				array(
					'visitor_family_clicks'          => true,
					'visitor_click_target_allowlist' => array( 'hero-cta' ),
				)
			)
		);

		$_POST['visitor_settings'] = array( 'visitor_tracking_enabled' => '1' );
		$nonce                     = wp_create_nonce( VisitorTrackingPage::NONCE_ACTION );
		$_POST['_wpnonce']         = $nonce;
		$_REQUEST['_wpnonce']      = $nonce;

		$this->saving_page()->handle_request();

		$saved = ( new Settings() )->get();
		$this->assertFalse( $saved['visitor_family_clicks'] );
		$this->assertSame( array(), $saved['visitor_click_target_allowlist'] );

		unset( $_POST['visitor_settings'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );
	}

	public function test_render_offers_the_digest_fieldset(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		ob_start();
		$this->page()->render_tab_content();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Visitor Activity Digest', $output );
		$this->assertStringContainsString( 'visitor_settings[visitor_digest_enabled]', $output );
		$this->assertStringContainsString( 'visitor_settings[visitor_digest_threshold]', $output );
		$this->assertStringContainsString( 'visitor_settings[visitor_digest_max_wait_minutes]', $output );
	}

	/**
	 * A destination created for a website-chat conversation must never be
	 * offered as a digest target — the dropdown is populated from
	 * DigestEligibility::eligible_destinations_for_bot(), which excludes it
	 * (docs/plans/m11a-visitor-activity-digests-plan-v1.md §4).
	 */
	public function test_destination_dropdown_never_lists_a_conversation_linked_destination(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$bots = $this->bots();
		$bot  = $bots->create( 'Digest Bot', 'token' );
		$bots->set_status( $bot->id(), BotStatus::ACTIVE );

		$destinations = $this->destinations();
		$manual       = $destinations->create( $bot->id(), DestinationKind::CHANNEL, '@manual', null, 'Manual Channel' );
		$conv_dest    = $destinations->create( $bot->id(), DestinationKind::SUPERGROUP, '-100555', 3, 'Conversation Topic' );

		$conversations = $this->conversations();
		$conversation  = $conversations->create( wp_generate_uuid4(), hash( 'sha256', 'x' ), $bot->id(), null );
		$conversations->set_destination( $conversation->id(), $conv_dest->id() );

		update_option(
			Settings::OPTION_NAME,
			( new Settings() )->sanitize( array( 'visitor_digest_bot_id' => $bot->id() ) )
		);

		$page = new VisitorTrackingPage(
			new Settings(),
			$bots,
			new DigestEligibility( new Settings(), $bots, $destinations, $conversations )
		);

		ob_start();
		$page->render_tab_content();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Manual Channel', $output );
		$this->assertStringNotContainsString( 'Conversation Topic', $output );
	}

	public function test_saving_the_digest_fields_persists_them_and_invalidates_the_eligibility_cache(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$bots = $this->bots();
		$bot  = $bots->create( 'Digest Bot', 'token' );
		$bots->set_status( $bot->id(), BotStatus::ACTIVE );
		$destination = $this->destinations()->create( $bot->id(), DestinationKind::CHANNEL, '@manual', null, 'Manual Channel' );

		// Warm the cache with a known (false) value before the save, so the
		// test can prove the save itself invalidated it, not merely that a
		// fresh read happens to compute true.
		set_transient( DigestEligibility::TRANSIENT_KEY, '0', 30 );

		$_POST['visitor_settings'] = array(
			'visitor_tracking_enabled'        => '1',
			'visitor_digest_enabled'          => '1',
			'visitor_digest_bot_id'           => (string) $bot->id(),
			'visitor_digest_destination_id'   => (string) $destination->id(),
			'visitor_digest_threshold'        => '75',
			'visitor_digest_max_wait_minutes' => '20',
		);
		$nonce                     = wp_create_nonce( VisitorTrackingPage::NONCE_ACTION );
		$_POST['_wpnonce']         = $nonce;
		$_REQUEST['_wpnonce']      = $nonce;

		$this->saving_page()->handle_request();

		$saved = ( new Settings() )->get();
		$this->assertTrue( $saved['visitor_digest_enabled'] );
		$this->assertSame( $bot->id(), $saved['visitor_digest_bot_id'] );
		$this->assertSame( $destination->id(), $saved['visitor_digest_destination_id'] );
		$this->assertSame( 75, $saved['visitor_digest_threshold'] );
		$this->assertSame( 20, $saved['visitor_digest_max_wait_minutes'] );

		$this->assertFalse( get_transient( DigestEligibility::TRANSIENT_KEY ) );

		unset( $_POST['visitor_settings'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );
	}
}
