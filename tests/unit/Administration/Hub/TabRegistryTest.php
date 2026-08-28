<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Administration\Hub;

use PHPUnit\Framework\TestCase;
use UniversalTelegram\Administration\Hub\Tab;
use UniversalTelegram\Administration\Hub\TabRegistry;

final class TabRegistryTest extends TestCase {

	public function test_the_first_registered_tab_is_the_default(): void {
		$registry = new TabRegistry();
		$overview = new Tab( 'overview', 'Overview', 'manage', static function (): void {} );
		$bots     = new Tab( 'bots', 'Bots', 'manage', static function (): void {} );

		$registry->register( $overview );
		$registry->register( $bots );

		$this->assertSame( 'overview', $registry->default()->id() );
	}

	public function test_a_registered_tab_is_retrievable_by_id(): void {
		$registry = new TabRegistry();
		$bots     = new Tab( 'bots', 'Bots', 'manage', static function (): void {} );
		$registry->register( $bots );

		$this->assertSame( $bots, $registry->get( 'bots' ) );
	}

	public function test_an_unregistered_id_returns_null(): void {
		$registry = new TabRegistry();
		$registry->register( new Tab( 'overview', 'Overview', 'manage', static function (): void {} ) );

		$this->assertNull( $registry->get( 'does-not-exist' ) );
	}

	public function test_all_returns_tabs_in_registration_order(): void {
		$registry = new TabRegistry();
		$registry->register( new Tab( 'overview', 'Overview', 'manage', static function (): void {} ) );
		$registry->register( new Tab( 'bots', 'Bots', 'manage', static function (): void {} ) );
		$registry->register( new Tab( 'settings', 'Settings', 'manage', static function (): void {} ) );

		$this->assertSame(
			array( 'overview', 'bots', 'settings' ),
			array_map( static fn ( Tab $tab ): string => $tab->id(), $registry->all() )
		);
	}
}
