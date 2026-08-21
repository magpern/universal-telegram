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
}
