<?php
/**
 * Ordered registry of administration hub tabs.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Administration\Hub;

/**
 * Insertion order is display order. The first registered tab is the
 * default (ADR-0020, Decision item 1) — `overview` in this milestone's
 * own registration order (Core\Plugin::init()), but this class makes no
 * assumption about which tab that is.
 */
final class TabRegistry {

	/**
	 * Every registered tab, keyed by id, in registration order.
	 *
	 * @var array<string, Tab>
	 */
	private array $tabs = array();

	/**
	 * Registers one tab. Insertion order is display order.
	 *
	 * @param Tab $tab The tab to register.
	 */
	public function register( Tab $tab ): void {
		$this->tabs[ $tab->id() ] = $tab;
	}

	/**
	 * Every registered tab, in registration order.
	 *
	 * @return array<int, Tab>
	 */
	public function all(): array {
		return array_values( $this->tabs );
	}

	/**
	 * The tab registered under this id, or null if none is.
	 *
	 * @param string $id The tab id to look up.
	 */
	public function get( string $id ): ?Tab {
		return $this->tabs[ $id ] ?? null;
	}

	/**
	 * The first tab registered. Callers must register at least one tab
	 * before calling this.
	 */
	public function default(): Tab {
		return array_values( $this->tabs )[0];
	}
}
