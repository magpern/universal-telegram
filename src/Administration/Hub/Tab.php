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
 * navigation (ADR-0020). `capability()` is re-verified independently by
 * HubPage::render() before `render()` is ever invoked, mirroring the
 * defense-in-depth check every migrated page already had on its own.
 */
final class Tab {

	/**
	 * @param string   $id         The `tab` URL query value (lowercase, hyphenated).
	 * @param string   $label      The visible tab label.
	 * @param string   $capability A CapabilityRegistrar constant.
	 * @param callable $render     Renders this tab's content only (no outer .wrap/<h1>).
	 */
	public function __construct(
		private readonly string $id,
		private readonly string $label,
		private readonly string $capability,
		private readonly $render
	) {}

	public function id(): string {
		return $this->id;
	}

	public function label(): string {
		return $this->label;
	}

	public function capability(): string {
		return $this->capability;
	}

	public function render(): void {
		( $this->render )();
	}
}
