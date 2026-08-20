<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Core;

use PHPUnit\Framework\TestCase;

/**
 * The six boundaries M00 does not implement (Events, Automations,
 * Telegram, Conversations, ChatWidget, AI — see docs/ARCHITECTURE.md)
 * must not exist under src/ until their own owning milestone's frozen
 * plan authorizes creating them.
 */
final class StructuralBoundariesTest extends TestCase {

	/**
	 * @return array<string, array{0: string}>
	 */
	public function undocumented_boundaries_provider(): array {
		return array(
			'Events'        => array( 'Events' ),
			'Automations'   => array( 'Automations' ),
			'Telegram'      => array( 'Telegram' ),
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
