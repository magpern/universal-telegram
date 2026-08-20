<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Events;

use PHPUnit\Framework\TestCase;
use UniversalTelegram\Events\EventEnvelope;
use UniversalTelegram\Events\EventIdentity;
use UniversalTelegram\Events\EventSource;
use UniversalTelegram\Events\InvalidIdempotencyKeyException;
use UniversalTelegram\Events\Registry;
use UniversalTelegram\Events\UnclassifiedFieldException;
use UniversalTelegram\Events\UnregisteredEventTypeException;
use UniversalTelegram\Privacy\Classification;

final class EventEnvelopeTest extends TestCase {

	private function registry_with_user_registered(): Registry {
		$registry = new Registry();
		$registry->register(
			'wordpress.user_registered',
			1,
			array(
				'subject.user_id' => Classification::PUBLIC,
				'context.ip_hash' => Classification::INTERNAL,
			),
			array( 'subject.user_id', 'context.ip_hash' ),
			array( 'subject.user_id' )
		);

		return $registry;
	}

	public function test_constructs_with_the_deterministic_event_id(): void {
		$registry = $this->registry_with_user_registered();

		$envelope = new EventEnvelope(
			$registry,
			'wordpress.user_registered',
			'idem-key',
			EventSource::WORDPRESS_CORE,
			array(),
			array( 'user_id' => 42 ),
			array( 'ip_hash' => 'abc' ),
			array()
		);

		$this->assertSame( EventIdentity::derive( 'wordpress.user_registered', 1, 'idem-key' ), $envelope->event_id() );
		$this->assertSame( 'wordpress.user_registered', $envelope->event_type() );
		$this->assertSame( 1, $envelope->schema_version() );
		$this->assertSame( 42, $envelope->value_at( 'subject.user_id' ) );
		$this->assertSame( 'abc', $envelope->value_at( 'context.ip_hash' ) );
	}

	public function test_an_unregistered_event_type_throws(): void {
		$registry = new Registry();

		$this->expectException( UnregisteredEventTypeException::class );
		new EventEnvelope( $registry, 'wordpress.never_registered', 'key', EventSource::WORDPRESS_CORE, array(), array(), array(), array() );
	}

	public function test_an_empty_idempotency_key_throws(): void {
		$registry = $this->registry_with_user_registered();

		$this->expectException( InvalidIdempotencyKeyException::class );
		new EventEnvelope( $registry, 'wordpress.user_registered', '', EventSource::WORDPRESS_CORE, array(), array(), array(), array() );
	}

	public function test_an_idempotency_key_over_255_bytes_throws(): void {
		$registry = $this->registry_with_user_registered();

		$this->expectException( InvalidIdempotencyKeyException::class );
		new EventEnvelope( $registry, 'wordpress.user_registered', str_repeat( 'a', 256 ), EventSource::WORDPRESS_CORE, array(), array(), array(), array() );
	}

	public function test_an_unclassified_field_throws(): void {
		$registry = $this->registry_with_user_registered();

		$this->expectException( UnclassifiedFieldException::class );
		new EventEnvelope(
			$registry,
			'wordpress.user_registered',
			'key',
			EventSource::WORDPRESS_CORE,
			array(),
			array( 'user_id' => 42, 'unclassified_field' => 'x' ),
			array(),
			array()
		);
	}

	public function test_replaying_the_same_idempotency_key_produces_the_same_event_id(): void {
		$registry = $this->registry_with_user_registered();

		$first  = new EventEnvelope( $registry, 'wordpress.user_registered', 'same-key', EventSource::WORDPRESS_CORE, array(), array( 'user_id' => 1 ), array(), array() );
		$second = new EventEnvelope( $registry, 'wordpress.user_registered', 'same-key', EventSource::WORDPRESS_CORE, array(), array( 'user_id' => 2 ), array(), array() );

		$this->assertSame( $first->event_id(), $second->event_id() );
	}

	public function test_a_distinct_idempotency_key_produces_a_distinct_event_id(): void {
		$registry = $this->registry_with_user_registered();

		$first  = new EventEnvelope( $registry, 'wordpress.user_registered', 'key-one', EventSource::WORDPRESS_CORE, array(), array(), array(), array() );
		$second = new EventEnvelope( $registry, 'wordpress.user_registered', 'key-two', EventSource::WORDPRESS_CORE, array(), array(), array(), array() );

		$this->assertNotSame( $first->event_id(), $second->event_id() );
	}

	public function test_value_at_returns_null_for_a_missing_path(): void {
		$registry = $this->registry_with_user_registered();

		$envelope = new EventEnvelope( $registry, 'wordpress.user_registered', 'key', EventSource::WORDPRESS_CORE, array(), array(), array(), array() );

		$this->assertNull( $envelope->value_at( 'subject.user_id' ) );
		$this->assertNull( $envelope->value_at( 'nonexistent_section.x' ) );
	}
}
