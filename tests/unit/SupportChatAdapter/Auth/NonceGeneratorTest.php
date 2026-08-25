<?php
/**
 * Unit tests for ADR-0007 §3 nonce generation.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Tests\SupportChatAdapter\Auth;

use PHPUnit\Framework\TestCase;
use UniversalTelegram\SupportChatAdapter\Auth\NonceGenerator;

/**
 * @covers \UniversalTelegram\SupportChatAdapter\Auth\NonceGenerator
 */
final class NonceGeneratorTest extends TestCase {

	public function test_generate_produces_well_formed_unique_nonces(): void {
		$a = NonceGenerator::generate();
		$b = NonceGenerator::generate();

		$this->assertTrue( NonceGenerator::is_valid_format( $a ) );
		$this->assertTrue( NonceGenerator::is_valid_format( $b ) );
		$this->assertNotSame( $a, $b );
		$this->assertSame( 22, strlen( $a ) );
	}

	/**
	 * @dataProvider malformed_nonce_provider
	 */
	public function test_is_valid_format_rejects_malformed_values( string $candidate ): void {
		$this->assertFalse( NonceGenerator::is_valid_format( $candidate ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function malformed_nonce_provider(): array {
		return array(
			'too short'      => array( str_repeat( 'a', 21 ) ),
			'too long'       => array( str_repeat( 'a', 23 ) ),
			'padded base64'  => array( str_repeat( 'a', 20 ) . '==' ),
			'contains plus'  => array( str_repeat( 'a', 21 ) . '+' ),
			'contains slash' => array( str_repeat( 'a', 21 ) . '/' ),
			'empty'          => array( '' ),
		);
	}
}
