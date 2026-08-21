<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Conversations;

use UniversalTelegram\Conversations\VisitorTokenGenerator;
use WP_UnitTestCase;

final class VisitorTokenGeneratorTest extends WP_UnitTestCase {

	public function test_generate_produces_a_uuid_a_secret_and_a_matching_hash(): void {
		$generator = new VisitorTokenGenerator();

		$credential = $generator->generate();

		$this->assertMatchesRegularExpression(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
			$credential['conversation_uuid']
		);
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $credential['secret'] );
		$this->assertTrue( $generator->verify( $credential['secret'], $credential['secret_hash'] ) );
	}

	public function test_verify_rejects_a_wrong_secret(): void {
		$generator = new VisitorTokenGenerator();

		$credential = $generator->generate();

		$this->assertFalse( $generator->verify( 'a-completely-different-secret', $credential['secret_hash'] ) );
	}

	public function test_two_generated_credentials_never_collide(): void {
		$generator = new VisitorTokenGenerator();

		$first  = $generator->generate();
		$second = $generator->generate();

		$this->assertNotSame( $first['conversation_uuid'], $second['conversation_uuid'] );
		$this->assertNotSame( $first['secret'], $second['secret'] );
	}

	public function test_generate_uuid_produces_a_valid_uuid(): void {
		$generator = new VisitorTokenGenerator();

		$this->assertMatchesRegularExpression(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
			$generator->generate_uuid()
		);
	}

	public function test_is_valid_secret_format_accepts_only_64_lowercase_hex_characters(): void {
		$generator = new VisitorTokenGenerator();

		$this->assertTrue( $generator->is_valid_secret_format( bin2hex( random_bytes( 32 ) ) ) );
		$this->assertFalse( $generator->is_valid_secret_format( '' ) );
		$this->assertFalse( $generator->is_valid_secret_format( 'too-short' ) );
		$this->assertFalse( $generator->is_valid_secret_format( str_repeat( 'F', 64 ) ) );
		$this->assertFalse( $generator->is_valid_secret_format( str_repeat( 'a', 65 ) ) );
	}

	public function test_hash_produces_a_hash_that_verify_accepts(): void {
		$generator = new VisitorTokenGenerator();
		$secret    = bin2hex( random_bytes( 32 ) );

		$this->assertTrue( $generator->verify( $secret, $generator->hash( $secret ) ) );
	}
}
