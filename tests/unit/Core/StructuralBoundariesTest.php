<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Core;

use PHPUnit\Framework\TestCase;

/**
 * The five boundaries M01 does not implement (Events, Automations,
 * Conversations, ChatWidget, AI — see docs/ARCHITECTURE.md) must not exist
 * under src/ until their own owning milestone's frozen plan authorizes
 * creating them. Telegram was permitted starting at M01
 * (docs/plans/m01-telegram-connectivity-plan-v1.md).
 */
final class StructuralBoundariesTest extends TestCase {

	/**
	 * @return array<string, array{0: string}>
	 */
	public function undocumented_boundaries_provider(): array {
		return array(
			'Events'        => array( 'Events' ),
			'Automations'   => array( 'Automations' ),
			'Conversations' => array( 'Conversations' ),
			'ChatWidget'    => array( 'ChatWidget' ),
			'AI'            => array( 'AI' ),
		);
	}

	/**
	 * @dataProvider undocumented_boundaries_provider
	 */
	public function test_boundary_directory_does_not_yet_exist( string $boundary ): void {
		$path = dirname( __DIR__, 3 ) . '/src/' . $boundary;

		$this->assertDirectoryDoesNotExist( $path );
	}
}
