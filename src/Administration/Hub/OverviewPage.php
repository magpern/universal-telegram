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
	 * Ordered (tab id, label) pairs.
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	private const OTHER_TABS = array(
		array( 'bots', 'Bots' ),
		array( 'events', 'Events' ),
		array( 'rules', 'Notifications' ),
		array( 'test-notifications', 'Test notifications' ),
		array( 'event-history', 'Event History' ),
		array( 'visitor-tracking', 'Visitor Tracking' ),
		array( 'settings', 'Settings' ),
		array( 'diagnostics', 'Diagnostics' ),
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
		foreach ( self::OTHER_TABS as [ $tab_id, $label ] ) {
			printf(
				'<li><a href="%s">%s</a></li>',
				esc_url( admin_url( 'admin.php?page=' . HubPage::SLUG . '&tab=' . $tab_id ) ),
				esc_html( $label )
			);
		}
		echo '</ul>';
	}
}
