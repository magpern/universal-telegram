<?php
/**
 * Compatibility entry points for every retired admin submenu slug.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Administration\Hub;

use UniversalTelegram\Administration\Automations\EventCatalogPage;
use UniversalTelegram\Administration\Automations\EventHistoryPage;
use UniversalTelegram\Administration\Automations\RuleBuilderPage;
use UniversalTelegram\Administration\Automations\NotificationTesterPage;
use UniversalTelegram\Administration\Diagnostics\DiagnosticsPage;
use UniversalTelegram\Administration\Telegram\BotManagementPage;
use UniversalTelegram\Administration\Visitor\VisitorTrackingPage;
use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;

/**
 * ADR-0020: every admin page slug the plugin registered before the
 * administration hub existed is preserved permanently as a hidden
 * (parent_slug = null), capability-gated compatibility entry point. Only
 * a GET request, from a user holding the original screen's own
 * capability, is redirected — temporarily (302), never permanently
 * (301) — to the slug's equivalent Hub tab. Capability is always checked
 * first, before any redirect target is computed or any content is
 * disclosed. A non-GET request never triggers a redirect (M04.1 plan §5):
 * no current mutation route is tied to admin.php?page= at all — every
 * mutation already goes through the separate admin-post.php surface,
 * entirely untouched by this class — but the check exists so a future
 * request of that kind is never silently carried forward to a new
 * location.
 *
 * Not declared final: tests override redirect_and_exit() to avoid a real
 * exit call terminating the test process, matching
 * VisitorTrackingPage's exact existing precedent.
 */
class LegacyUrlRedirector {

	/**
	 * Old slug => [tab id, capability]. A method, not a class constant,
	 * so this never depends on cross-class constant expressions being
	 * resolvable at compile time.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	private static function map(): array {
		return array(
			DiagnosticsPage::SLUG     => array( 'diagnostics', CapabilityRegistrar::MANAGE ),
			BotManagementPage::SLUG   => array( BotManagementPage::TAB_ID, CapabilityRegistrar::MANAGE ),
			EventCatalogPage::SLUG    => array( 'events', CapabilityRegistrar::MANAGE_AUTOMATIONS ),
			RuleBuilderPage::SLUG     => array( RuleBuilderPage::TAB_ID, CapabilityRegistrar::MANAGE_AUTOMATIONS ),
			NotificationTesterPage::SLUG => array( NotificationTesterPage::TAB_ID, CapabilityRegistrar::MANAGE_AUTOMATIONS ),
			EventHistoryPage::SLUG    => array( EventHistoryPage::TAB_ID, CapabilityRegistrar::MANAGE_AUTOMATIONS ),
			VisitorTrackingPage::SLUG => array( VisitorTrackingPage::TAB_ID, CapabilityRegistrar::MANAGE ),
		);
	}

	/**
	 * Registers one hidden, reachable-by-URL admin page per retired slug.
	 */
	public function register(): void {
		foreach ( self::map() as $old_slug => [ , $capability ] ) {
			// An empty parent_slug is WordPress core's own documented
			// mechanism for a page that is reachable by URL but never
			// added to any visible menu (WP_Admin_Bar/admin_menu's own
			// "orphaned" page support), which is exactly what a hidden
			// compatibility redirect target needs.
			add_submenu_page(
				'',
				'',
				'',
				$capability,
				$old_slug,
				function () use ( $old_slug ): void {
					$this->redirect( $old_slug );
				}
			);
		}
	}

	/**
	 * The redirect callback for one retired slug.
	 *
	 * @param string $old_slug The retired admin page slug.
	 */
	public function redirect( string $old_slug ): void {
		[ $tab_id, $capability ] = self::map()[ $old_slug ];

		if ( ! current_user_can( $capability ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'universal-telegram' ) );
		}

		$target = admin_url( 'admin.php?page=' . HubPage::SLUG . '&tab=' . $tab_id );

		$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? (string) wp_unslash( $_SERVER['REQUEST_METHOD'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Recommended -- read-only method check, no state changed.

		if ( 'GET' !== $request_method ) {
			// A redirect must never be issued for a non-GET request: some
			// clients silently re-issue the original method against the
			// Location header, which would be unacceptable for any future
			// mutation aimed at this slug. Render a plain, capability-safe
			// notice instead of redirecting.
			printf(
				'<div class="wrap"><p>%s <a href="%s">%s</a></p></div>',
				esc_html__( 'This page has moved.', 'universal-telegram' ),
				esc_url( $target ),
				esc_html__( 'Go to the new location', 'universal-telegram' )
			);
			return;
		}

		$this->redirect_and_exit( $target );
	}

	/**
	 * Redirects (302, temporary) and terminates the request. Overridden
	 * by tests.
	 *
	 * @param string $url The destination URL.
	 */
	protected function redirect_and_exit( string $url ): void {
		wp_safe_redirect( $url, 302 );
		exit;
	}
}
