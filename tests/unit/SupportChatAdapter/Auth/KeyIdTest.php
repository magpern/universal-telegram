<?php
/**
 * Unit tests for the ADR-0007 §3 key-ID format.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Tests\SupportChatAdapter\Auth;

use PHPUnit\Framework\TestCase;
use UniversalTelegram\SupportChatAdapter\Auth\KeyId;

/**
 * @covers \UniversalTelegram\SupportChatAdapter\Auth\KeyId
 */
final class KeyIdTest extends TestCase {

	public function test_compute_is_deterministic_and_matches_expected_format(): void {
		$raw = str_repeat( "\x01", 32 );

		$key_id = KeyId::compute( 'universal-telegram', $raw );

		$this->assertMatchesRegularExpression( '/^universal-telegram\.[0-9a-f]{16}$/', $key_id );
		$this->assertSame( $key_id, KeyId::compute( 'universal-telegram', $raw ) );
	}

	public function test_compute_differs_for_different_keys(): void {
		$a = KeyId::compute( 'universal-telegram', str_repeat( "\x01", 32 ) );
		$b = KeyId::compute( 'universal-telegram', str_repeat( "\x02", 32 ) );

		$this->assertNotSame( $a, $b );
	}

	public function test_is_valid_format_accepts_well_formed_ids(): void {
		$this->assertTrue( KeyId::is_valid_format( 'universal-telegram.0123456789abcdef' ) );
	}

	/**
	 * @dataProvider malformed_key_id_provider
	 */
	public function test_is_valid_format_rejects_malformed_ids( string $candidate ): void {
		$this->assertFalse( KeyId::is_valid_format( $candidate ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function malformed_key_id_provider(): array {
		return array(
			'no dot'         => array( 'universaltelegram0123456789abcdef' ),
			'uppercase hex'  => array( 'universal-telegram.0123456789ABCDEF' ),
			'short hex'      => array( 'universal-telegram.0123456789abcde' ),
			'long hex'       => array( 'universal-telegram.0123456789abcdef0' ),
			'empty'          => array( '' ),
			'uppercase slug' => array( 'Universal-Telegram.0123456789abcdef' ),
		);
	}
}
