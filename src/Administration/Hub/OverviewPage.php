<?php
/**
 * The Hub's default landing tab.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Administration\Hub;

use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;

/**
 * A minimal, static welcome tab: no new data source, no new query, no
 * business logic (M04.1 plan §3) — a short introduction plus
 * capability-gated links to every other Hub tab. Diagnostics keeps its
 * own, unchanged report; this tab does not duplicate it.
 */
final class OverviewPage {

	public const TAB_ID = 'overview';

	/**
	 * Ordered (tab id, label, section id or null) triples. A non-null
	 * section routes into one of the M08.2-navigation-addendum grouped
	 * areas' own secondary tab rows, exactly like a bookmark to that
	 * former top-level tab now would (HubPage::LEGACY_TAB_ALIASES).
	 *
	 * @var array<int, array{0: string, 1: string, 2: string|null}>
	 */
	private const OTHER_TABS = array(
		array( 'bots', 'Bots', null ),
		array( 'notifications-activity', 'Events', 'events' ),
		array( 'notifications-activity', 'Notifications', 'rules' ),
		array( 'notifications-activity', 'Test notifications', 'test-notifications' ),
		array( 'notifications-activity', 'Event History', 'event-history' ),
		array( 'notifications-activity', 'Visitor Tracking', 'visitor-tracking' ),
		array( 'settings', 'Settings', null ),
		array( 'diagnostics', 'Diagnostics', null ),
	);

	/**
	 * Renders this tab's content only (no outer .wrap/<h1> — owned by HubPage).
	 */
	public function render_tab_content(): void {
		if ( ! current_user_can( CapabilityRegistrar::MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'universal-telegram' ) );
		}

		echo '<p>' . esc_html__( 'Welcome to the Telegram Operations Hub. Use the tabs above to configure bots, automation rules, visitor tracking, and plugin-wide settings.', 'universal-telegram' ) . '</p>';

		echo '<ul>';
		foreach ( self::OTHER_TABS as [ $tab_id, $label, $section_id ] ) {
			$url = 'admin.php?page=' . HubPage::SLUG . '&tab=' . $tab_id;
			if ( null !== $section_id ) {
				$url .= '&section=' . $section_id;
			}
			printf(
				'<li><a href="%s">%s</a></li>',
				esc_url( admin_url( $url ) ),
				esc_html( $label )
			);
		}
		echo '</ul>';
	}
}
