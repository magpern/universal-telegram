<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Core;

use PHPUnit\Framework\TestCase;

/**
 * Every boundary named in docs/ARCHITECTURE.md must not exist under src/
 * until its own owning milestone's frozen plan authorizes creating it.
 * Telegram was permitted starting at M01
 * (docs/plans/m01-telegram-connectivity-plan-v1.md); Events and
 * Automations were permitted starting at M02
 * (docs/plans/m02-normalized-events-and-notifications-plan-v1.md);
 * Conversations was permitted starting at M05
 * (docs/plans/m05-conversation-backend-plan-v1.md, docs/adr/0021);
 * ChatWidget was permitted starting at M06 core
 * (docs/plans/m06-chat-widget-plan-v1.md, docs/adr/0022); AI was
 * permitted starting at M09
 * (docs/plans/m09-ai-draft-assistant-plan-v1.md, docs/adr/0028) — see
 * AiDraftRepositoryAccessAllowListTest for the fixed six-class allow-list
 * that boundary's own structural prohibition on visitor/Telegram delivery
 * depends on.
 *
 * No boundary currently remains undocumented/not-yet-authorized; this
 * class is kept, with an empty provider, as the place a future milestone's
 * not-yet-authorized boundary is added back.
 */
final class StructuralBoundariesTest extends TestCase {

	/**
	 * @return array<string, array{0: string}>
	 */
	public function undocumented_boundaries_provider(): array {
		return array();
	}

	/**
	 * @dataProvider undocumented_boundaries_provider
	 */
	public function test_boundary_directory_does_not_yet_exist( string $boundary ): void {
		$path = dirname( __DIR__, 3 ) . '/src/' . $boundary;

		$this->assertDirectoryDoesNotExist( $path );
	}
}
