<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Privacy;

use PHPUnit\Framework\TestCase;
use UniversalTelegram\Privacy\Classification;
use UniversalTelegram\Privacy\Redactor;

final class RedactorTest extends TestCase {

	public function test_a_secret_field_is_stripped_entirely(): void {
		$redactor = new Redactor();

		$result = $redactor->redact(
			array( 'token' => 'abc123' ),
			array( 'token' => Classification::SECRET )
		);

		$this->assertArrayNotHasKey( 'token', $result );
	}

	public function test_a_sensitive_field_is_masked(): void {
		$redactor = new Redactor();

		$result = $redactor->redact(
			array( 'email' => 'someone@example.com' ),
			array( 'email' => Classification::SENSITIVE )
		);

		$this->assertSame( '***', $result['email'] );
	}

	public function test_a_public_field_passes_through_unchanged(): void {
		$redactor = new Redactor();

		$result = $redactor->redact(
			array( 'action' => 'job.enqueued' ),
			array( 'action' => Classification::PUBLIC )
		);

		$this->assertSame( 'job.enqueued', $result['action'] );
	}

	public function test_a_nested_field_is_handled_at_every_depth_via_dot_notation(): void {
		$redactor = new Redactor();

		$result = $redactor->redact(
			array(
				'job' => array(
					'id'     => 'job-1',
					'secret' => 'hidden',
				),
			),
			array(
				'job.id'     => Classification::PUBLIC,
				'job.secret' => Classification::SECRET,
			)
		);

		$this->assertSame( 'job-1', $result['job']['id'] );
		$this->assertArrayNotHasKey( 'secret', $result['job'] );
	}

	public function test_any_field_at_any_depth_missing_from_the_map_is_rejected_not_defaulted_to_public(): void {
		$redactor = new Redactor();

		$result = $redactor->redact(
			array(
				'known'   => 'value',
				'unknown' => 'value',
				'nested'  => array(
					'known_child'   => 'value',
					'unknown_child' => 'value',
				),
			),
			array(
				'known'              => Classification::PUBLIC,
				'nested.known_child' => Classification::PUBLIC,
			)
		);

		$this->assertArrayHasKey( 'known', $result );
		$this->assertArrayNotHasKey( 'unknown', $result );
		$this->assertArrayHasKey( 'known_child', $result['nested'] );
		$this->assertArrayNotHasKey( 'unknown_child', $result['nested'] );
	}
}
