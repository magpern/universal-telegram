<?php
/**
 * A grouped top-level Hub area with an accessible secondary tab row.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Administration\Hub;

/**
 * M08.2 navigation addendum: a presentation-only grouping of several
 * existing, unchanged Hub screens under one top-level area — reusing the
 * existing `Tab` value object for each "section" rather than inventing a
 * parallel page controller or duplicating any section's own data, logic,
 * or controls. Resolves the requested `section` GET value (a plain
 * catalog selector, exactly like HubPage's own `tab`); an unknown,
 * missing, or currently-inaccessible-to-this-viewer section falls back to
 * the first section the current user can access, never an error. Every
 * section's own capability check inside its render() remains the
 * authoritative, defense-in-depth gate — this class adds a second,
 * earlier check only so an inaccessible section is never listed as
 * selectable and never silently rendered by falling through to it.
 */
final class AreaPage {

	/**
	 * This area's own sections, keyed by section id.
	 *
	 * @var array<string, Tab>
	 */
	private array $sections = array();

	/**
	 * Constructor.
	 *
	 * @param string          $area_id      This area's own `tab` URL query value.
	 * @param string          $area_label   This area's own visible label, used only for the secondary nav's aria-label.
	 * @param array<int, Tab> $sections   This area's own child sections, in display order.
	 */
	public function __construct(
		private readonly string $area_id,
		private readonly string $area_label,
		array $sections
	) {
		foreach ( $sections as $section ) {
			$this->sections[ $section->id() ] = $section;
		}
	}

	/**
	 * Whether at least one section is accessible to the current viewer —
	 * the value HubPage itself uses both to decide whether this area's own
	 * top-level tab is listed in the nav at all, and to gate render().
	 */
	public function is_accessible(): bool {
		foreach ( $this->sections as $section ) {
			if ( $section->is_accessible() ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Renders the secondary section row plus the resolved section's own
	 * content (no outer .wrap/<h1> — owned by HubPage, exactly like every
	 * other top-level tab's own render_tab_content()).
	 */
	public function render_tab_content(): void {
		$section = $this->resolve_section();

		if ( null === $section ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'universal-telegram' ) );
		}

		$this->render_secondary_nav( $section->id() );
		$section->render();
	}

	/**
	 * The requested section, or the first accessible one as a safe
	 * fallback for a missing/unknown/inaccessible request, or null if the
	 * current viewer can access none of them at all.
	 */
	private function resolve_section(): ?Tab {
		$requested = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( (string) $_GET['section'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a GET-only catalog selector, no mutation, mirroring HubPage::resolve_tab_id()'s own `tab` handling.

		if ( '' !== $requested && isset( $this->sections[ $requested ] ) && $this->sections[ $requested ]->is_accessible() ) {
			return $this->sections[ $requested ];
		}

		foreach ( $this->sections as $section ) {
			if ( $section->is_accessible() ) {
				return $section;
			}
		}

		return null;
	}

	/**
	 * The secondary tab row: the same nav-tab/nav-tab-active WordPress-
	 * admin classes HubPage::render_tab_nav() already uses for the
	 * top-level row (no new CSS system), one real `<a href>` per
	 * accessible section, aria-current on the active one, a lighter
	 * heading level than the top row's own `<h2>` to keep the visual
	 * hierarchy correct without a third navigation level.
	 *
	 * @param string $active_section_id The currently resolved section id.
	 */
	private function render_secondary_nav( string $active_section_id ): void {
		echo '<h3 class="nav-tab-wrapper ut-area-subnav" aria-label="' . esc_attr(
			/* translators: %s: the area's own label, e.g. "Notifications & activity" */
			sprintf( __( '%s sections', 'universal-telegram' ), $this->area_label )
		) . '">';

		foreach ( $this->sections as $section ) {
			if ( ! $section->is_accessible() ) {
				continue;
			}

			$is_active = $section->id() === $active_section_id;
			$url       = admin_url( 'admin.php?page=' . HubPage::SLUG . '&tab=' . $this->area_id . '&section=' . $section->id() );

			printf(
				'<a href="%s" class="%s"%s>%s</a>',
				esc_url( $url ),
				esc_attr( 'nav-tab' . ( $is_active ? ' nav-tab-active' : '' ) ),
				$is_active ? ' aria-current="page"' : '',
				esc_html( $section->label() )
			);
		}

		echo '</h3>';
		echo '<style>.ut-area-subnav { margin-top: -1px; }</style>';
	}
}
