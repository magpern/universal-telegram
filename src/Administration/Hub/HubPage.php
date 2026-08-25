<?php
/**
 * The administration hub shell: one top-level menu entry, URL-driven tabs.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Administration\Hub;

use UniversalTelegram\Administration\AI\ApprovedContentPage;
use UniversalTelegram\Administration\Automations\EventHistoryPage;
use UniversalTelegram\Administration\Automations\NotificationTesterPage;
use UniversalTelegram\Administration\Automations\RuleBuilderPage;
use UniversalTelegram\Administration\Conversations\ConversationInboxPage;
use UniversalTelegram\Administration\Conversations\OperatorIdentityPage;
use UniversalTelegram\Administration\Visitor\VisitorTrackingPage;
use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;

/**
 * Single WordPress admin menu entry ("Telegram Hub", ADR-0020), replacing
 * one top-level page plus six submenu pages. Resolves `$_GET['tab']`
 * against a TabRegistry; an absent or unknown value silently falls back
 * to the registry's default tab (never an error, never a redirect); a
 * tab the current user cannot access (Tab::is_accessible()) produces
 * WordPress' own wp_die() insufficient-permissions behavior — the tab's
 * content is never rendered and never disclosed in that case. Gated on
 * the broader of the plugin's two existing capabilities
 * (CapabilityRegistrar::MANAGE) since both are always co-granted to the
 * administrator role today; each tab still independently re-verifies its
 * own capability before its content renders.
 */
final class HubPage {

	public const SLUG = 'universal-telegram';

	/**
	 * Old, no-longer-registered `tab` id => [current area id, current
	 * section id] — the tab-id-level counterpart of LegacyUrlRedirector's
	 * own page-slug-level alias table (ADR-0020's "old identifiers
	 * preserved permanently" pattern). Covers both the M08.2 Simulator
	 * rename (`simulator`, now two hops deep) and every screen the M08.2
	 * navigation addendum moved from its own top-level tab into a grouped
	 * area's secondary section row — without this, resolve_tab_id() would
	 * silently fall back to the registry's default tab for any of these
	 * ids, indistinguishable from a typo. Reusable for any future
	 * rename/regroup, not special-cased to this one.
	 *
	 * @var array<string, array{0: string, 1: string}>
	 */
	private const LEGACY_TAB_ALIASES = array(
		'simulator'                        => array( 'notifications-activity', NotificationTesterPage::TAB_ID ),
		RuleBuilderPage::TAB_ID            => array( 'notifications-activity', RuleBuilderPage::TAB_ID ),
		NotificationTesterPage::TAB_ID     => array( 'notifications-activity', NotificationTesterPage::TAB_ID ),
		'events'                           => array( 'notifications-activity', 'events' ),
		EventHistoryPage::TAB_ID           => array( 'notifications-activity', EventHistoryPage::TAB_ID ),
		VisitorTrackingPage::TAB_ID        => array( 'notifications-activity', VisitorTrackingPage::TAB_ID ),
		ConversationInboxPage::TAB_ID      => array( 'conversations', ConversationInboxPage::TAB_ID ),
		OperatorIdentityPage::TAB_ID       => array( 'conversations', OperatorIdentityPage::TAB_ID ),
		'ai'                               => array( 'ai-hub', 'ai' ),
		ApprovedContentPage::TAB_ID        => array( 'ai-hub', ApprovedContentPage::TAB_ID ),
	);

	/**
	 * Constructor.
	 *
	 * @param TabRegistry $tabs Every registered Hub tab.
	 */
	public function __construct( private readonly TabRegistry $tabs ) {}

	/**
	 * Registers the single top-level admin menu entry.
	 */
	public function register_menu(): void {
		add_menu_page(
			__( 'Telegram Operations Hub', 'universal-telegram' ),
			__( 'Telegram Hub', 'universal-telegram' ),
			CapabilityRegistrar::MANAGE,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Resolves the requested tab id. A known legacy alias is transparently
	 * resolved to its current [area, section] first, setting $_GET['section']
	 * so the area's own AreaPage lands on the exact former screen. An
	 * absent or otherwise-unregistered value is treated identically to no
	 * request at all: the registry's default tab id, never an error.
	 */
	public function resolve_tab_id(): string {
		$requested = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( isset( self::LEGACY_TAB_ALIASES[ $requested ] ) ) {
			[ $area_id, $section_id ] = self::LEGACY_TAB_ALIASES[ $requested ];
			$_GET['section']          = $section_id; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a GET-only catalog selector we are ourselves resolving, no mutation.
			$requested                = $area_id;
		}

		if ( '' !== $requested && null !== $this->tabs->get( $requested ) ) {
			return $requested;
		}

		return $this->tabs->default()->id();
	}

	/**
	 * Renders the shell: shared .wrap/<h1>, the horizontal tab nav, and
	 * the resolved tab's own content.
	 */
	public function render(): void {
		$tab = $this->tabs->get( $this->resolve_tab_id() );

		if ( null === $tab ) {
			$tab = $this->tabs->default();
		}

		if ( ! $tab->is_accessible() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'universal-telegram' ) );
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Telegram Operations Hub', 'universal-telegram' ) . '</h1>';
		$this->render_tab_nav( $tab->id() );
		$tab->render();
		echo '</div>';
	}

	/**
	 * Renders the horizontal tab navigation: standard WordPress-admin
	 * nav-tab-wrapper markup, one real <a href> per tab (native keyboard
	 * reachability, no JS tab switching), aria-current on the active tab.
	 * A tab is skipped only if it opted into a custom accessibility check
	 * (Tab::has_accessibility_override(), used only by the three grouped
	 * navigation areas, M08.2 addendum) and that check currently fails —
	 * every other tab is listed exactly as before this addendum, deferring
	 * entirely to its own render-time capability check.
	 *
	 * @param string $active_tab_id The currently resolved tab id.
	 */
	private function render_tab_nav( string $active_tab_id ): void {
		echo '<h2 class="nav-tab-wrapper" aria-label="' . esc_attr__( 'Telegram Hub sections', 'universal-telegram' ) . '">';

		foreach ( $this->tabs->all() as $tab ) {
			if ( $tab->has_accessibility_override() && ! $tab->is_accessible() ) {
				continue;
			}

			$is_active = $tab->id() === $active_tab_id;
			$classes   = 'nav-tab' . ( $is_active ? ' nav-tab-active' : '' );
			$url       = admin_url( 'admin.php?page=' . self::SLUG . '&tab=' . $tab->id() );

			printf(
				'<a href="%s" class="%s"%s>%s</a>',
				esc_url( $url ),
				esc_attr( $classes ),
				$is_active ? ' aria-current="page"' : '',
				esc_html( $tab->label() )
			);
		}

		echo '</h2>';
	}
}
