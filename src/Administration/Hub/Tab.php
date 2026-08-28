<?php
/**
 * A single administration hub tab definition.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Administration\Hub;

/**
 * Immutable value object: one entry in the Hub's horizontal tab
 * navigation (ADR-0020), and — reused unchanged, M08.2 navigation
 * addendum — one entry in an AreaPage's own secondary section row.
 * `capability()` is re-verified independently by HubPage::render() before
 * `render()` is ever invoked, mirroring the defense-in-depth check every
 * migrated page already had on its own.
 */
final class Tab {

	/**
	 * Renders this tab's content only (no outer .wrap/<h1>).
	 *
	 * @var \Closure
	 */
	private readonly \Closure $render;

	/**
	 * Overrides is_accessible() when supplied — a niladic closure
	 * returning bool. Every pre-existing call site omits this, so
	 * is_accessible() falls back to current_user_can($capability) for
	 * them, byte-identical to this class's original behavior. Used only
	 * by the three grouped-navigation area tabs (M08.2 navigation
	 * addendum), whose accessibility is "at least one child's own
	 * capability passes" — not expressible as a single capability string.
	 *
	 * @var \Closure|null
	 */
	private readonly ?\Closure $accessible;

	/**
	 * Constructor.
	 *
	 * @param string        $id         The `tab` (or, for a section, `section`) URL query value (lowercase, hyphenated).
	 * @param string        $label      The visible tab label.
	 * @param string        $capability A CapabilityRegistrar constant. Still required even when $accessible is supplied — kept as the nominal capability for reference/logging, but not consulted by is_accessible() in that case.
	 * @param callable      $render     Renders this tab's content only (no outer .wrap/<h1>).
	 * @param callable|null $accessible Optional override for is_accessible(); see property doc.
	 */
	public function __construct(
		private readonly string $id,
		private readonly string $label,
		private readonly string $capability,
		callable $render,
		?callable $accessible = null
	) {
		$this->render     = \Closure::fromCallable( $render );
		$this->accessible = null !== $accessible ? \Closure::fromCallable( $accessible ) : null;
	}

	/**
	 * The `tab` (or `section`) URL query value.
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * The visible tab label.
	 */
	public function label(): string {
		return $this->label;
	}

	/**
	 * The CapabilityRegistrar constant gating this tab.
	 */
	public function capability(): string {
		return $this->capability;
	}

	/**
	 * Whether the current viewer may access this tab: the custom
	 * $accessible closure's result when supplied, else
	 * current_user_can($capability) — identical to every pre-M08.2-
	 * navigation-addendum call site's own inline check.
	 */
	public function is_accessible(): bool {
		return null !== $this->accessible ? ( $this->accessible )() : current_user_can( $this->capability );
	}

	/**
	 * Whether this tab supplied a custom accessibility check — only such
	 * tabs are ever hidden from the nav row when inaccessible; every other
	 * tab is always listed, exactly as before this addendum, deferring
	 * entirely to its own render-time capability check.
	 */
	public function has_accessibility_override(): bool {
		return null !== $this->accessible;
	}

	/**
	 * Renders this tab's content only (no outer .wrap/<h1>).
	 */
	public function render(): void {
		( $this->render )();
	}
}
