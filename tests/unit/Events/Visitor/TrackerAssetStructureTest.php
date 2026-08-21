<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Unit\Events\Visitor;

use PHPUnit\Framework\TestCase;

/**
 * Supplementary structural guard for assets/js/visitor-tracker.js
 * (M04 plan §7). Not the primary evidence for the tracker's correctness —
 * see tests/js/visitor-tracker.test.mjs for the behavioural suite.
 */
final class TrackerAssetStructureTest extends TestCase {

	private const DISALLOWED_TOKENS = array(
		'eval(',
		'document.cookie =',
		'localStorage',
		'XMLHttpRequest',
		'http://',
		'https://',
	);

	private function asset_path(): string {
		return dirname( __DIR__, 4 ) . '/assets/js/visitor-tracker.js';
	}

	public function test_asset_file_exists(): void {
		$this->assertFileExists( $this->asset_path() );
	}

	public function test_asset_is_at_most_8192_bytes(): void {
		$this->assertLessThanOrEqual( 8192, filesize( $this->asset_path() ) );
	}

	public function test_asset_is_valid_utf8(): void {
		$contents = file_get_contents( $this->asset_path() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertTrue( mb_check_encoding( $contents, 'UTF-8' ) );
	}

	/**
	 * @dataProvider disallowed_token_provider
	 */
	public function test_asset_contains_no_disallowed_token( string $token ): void {
		$contents = file_get_contents( $this->asset_path() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertStringNotContainsString( $token, $contents );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function disallowed_token_provider(): array {
		$cases = array();
		foreach ( self::DISALLOWED_TOKENS as $token ) {
			$cases[ $token ] = array( $token );
		}
		return $cases;
	}
}
