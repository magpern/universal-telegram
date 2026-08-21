<?php
/**
 * The administration hub shell: one top-level menu entry, URL-driven tabs.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Administration\Hub;

use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;

/**
 * Single WordPress admin menu entry ("Telegram Hub", ADR-0020), replacing
 * one top-level page plus six submenu pages. Resolves `$_GET['tab']`
 * against a TabRegistry; an absent or unknown value silently falls back
 * to the registry's default tab (never an error, never a redirect); a
 * known tab whose capability the current user lacks produces WordPress'
 * own wp_die() insufficient-permissions behavior — the tab's content is
 * never rendered and never disclosed in that case. Gated on the broader
 * of the plugin's two existing capabilities (CapabilityRegistrar::MANAGE)
 * since both are always co-granted to the administrator role today; each
 * tab still independently re-verifies its own capability before its
 * content renders.
 */
final class HubPage {

	public const SLUG = 'universal-telegram';

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
	 * Resolves the requested tab id. An absent or unregistered value is
	 * treated identically to no request at all: the registry's default
	 * tab id, never an error.
	 */
	public function resolve_tab_id(): string {
		$requested = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

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

		if ( ! current_user_can( $tab->capability() ) ) {
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
	 */
	private function render_tab_nav( string $active_tab_id ): void {
		echo '<h2 class="nav-tab-wrapper" aria-label="' . esc_attr__( 'Telegram Hub sections', 'universal-telegram' ) . '">';

		foreach ( $this->tabs->all() as $tab ) {
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
