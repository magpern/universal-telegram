<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Telegram\Outbound;

use PHPUnit\Framework\TestCase;
use UniversalTelegram\Privacy\Classification;
use UniversalTelegram\Queue\JobEnvelope;
use UniversalTelegram\Queue\PayloadRejectedException;
use UniversalTelegram\Telegram\Outbound\MessageDispatcher;

/**
 * A structural proof, for this milestone's own job type, that JobEnvelope's
 * fail-closed payload-classification policy (docs/adr/0006) holds: message
 * text or a token can never reach a queued job's payload for
 * telegram_send_message.
 */
final class OutboundPayloadClassificationTest extends TestCase {

	public function test_the_real_send_payload_shape_is_accepted(): void {
		$envelope = new JobEnvelope(
			MessageDispatcher::JOB_TYPE,
			array(
				'message_uuid'   => 'uuid',
				'bot_id'         => 1,
				'destination_id' => 2,
			),
			array(
				'message_uuid'   => Classification::INTERNAL,
				'bot_id'         => Classification::INTERNAL,
				'destination_id' => Classification::INTERNAL,
			),
			1,
			'job-1'
		);

		$args = $envelope->to_action_args();

		$this->assertSame(
			array( 'message_uuid', 'bot_id', 'destination_id' ),
			array_keys( $args['payload'] )
		);
	}

	public function test_a_body_field_is_rejected(): void {
		$this->expectException( PayloadRejectedException::class );

		new JobEnvelope(
			MessageDispatcher::JOB_TYPE,
			array(
				'message_uuid' => 'uuid',
				'body'         => 'hello there',
			),
			array(
				'message_uuid' => Classification::INTERNAL,
				'body'         => Classification::SENSITIVE,
			)
		);
	}

	public function test_a_token_field_is_rejected(): void {
		$this->expectException( PayloadRejectedException::class );

		new JobEnvelope(
			MessageDispatcher::JOB_TYPE,
			array(
				'message_uuid' => 'uuid',
				'token'        => '123456:ABCDEF',
			),
			array(
				'message_uuid' => Classification::INTERNAL,
				'token'        => Classification::SECRET,
			)
		);
	}

	public function test_no_key_in_the_real_send_payload_contains_a_recognizable_body_or_token_field_name(): void {
		$envelope = new JobEnvelope(
			MessageDispatcher::JOB_TYPE,
			array(
				'message_uuid'   => 'uuid',
				'bot_id'         => 1,
				'destination_id' => 2,
			),
			array(
				'message_uuid'   => Classification::INTERNAL,
				'bot_id'         => Classification::INTERNAL,
				'destination_id' => Classification::INTERNAL,
			),
			1,
			'job-2'
		);

		$forbidden_keys = array( 'body', 'text', 'token', 'message', 'content', 'secret' );

		foreach ( array_keys( $envelope->payload() ) as $key ) {
			$this->assertNotContains( $key, $forbidden_keys );
		}
	}
}
