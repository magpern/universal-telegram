<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Queue;

use PHPUnit\Framework\TestCase;
use UniversalTelegram\Privacy\Classification;
use UniversalTelegram\Queue\JobEnvelope;
use UniversalTelegram\Queue\PayloadRejectedException;

final class JobEnvelopeTest extends TestCase {

	public function test_an_unclassified_field_is_rejected_immediately(): void {
		$this->expectException( PayloadRejectedException::class );

		new JobEnvelope( 'test.job', array( 'foo' => 'bar' ), array(), 1, 'job-1' );
	}

	public function test_a_sensitive_field_is_rejected_immediately(): void {
		$this->expectException( PayloadRejectedException::class );

		new JobEnvelope(
			'test.job',
			array( 'foo' => 'bar' ),
			array( 'foo' => Classification::SENSITIVE ),
			1,
			'job-1'
		);
	}

	public function test_a_secret_field_is_rejected_immediately(): void {
		$this->expectException( PayloadRejectedException::class );

		new JobEnvelope(
			'test.job',
			array( 'foo' => 'bar' ),
			array( 'foo' => Classification::SECRET ),
			1,
			'job-1'
		);
	}

	public function test_public_and_internal_fields_are_accepted(): void {
		$envelope = new JobEnvelope(
			'test.job',
			array(
				'a' => '1',
				'b' => '2',
			),
			array(
				'a' => Classification::PUBLIC,
				'b' => Classification::INTERNAL,
			),
			1,
			'job-1'
		);

		$this->assertSame(
			array(
				'a' => '1',
				'b' => '2',
			),
			$envelope->payload()
		);
		$this->assertSame( 1, $envelope->attempt() );
		$this->assertSame( 'test.job', $envelope->job_type() );
		$this->assertSame( 'job-1', $envelope->job_id() );
	}

	public function test_to_action_args_carries_every_field(): void {
		$envelope = new JobEnvelope( 'test.job', array( 'a' => '1' ), array( 'a' => Classification::PUBLIC ), 2, 'job-2' );

		$this->assertSame(
			array(
				'job_id'   => 'job-2',
				'job_type' => 'test.job',
				'attempt'  => 2,
				'payload'  => array( 'a' => '1' ),
			),
			$envelope->to_action_args()
		);
	}
}
