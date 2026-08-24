<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Automations;

use PHPUnit\Framework\TestCase;
use UniversalTelegram\Automations\MarkdownV2Escaper;

final class MarkdownV2EscaperTest extends TestCase {

	public function test_escapes_timestamp_and_punctuation_for_markdown_v2(): void {
		$escaped = MarkdownV2Escaper::escape( '2026-01-01 00:00:00 (15 min)' );

		$this->assertSame( '2026\\-01\\-01 00:00:00 \\(15 min\\)', $escaped );
	}
}
