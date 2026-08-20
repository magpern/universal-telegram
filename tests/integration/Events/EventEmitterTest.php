<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Events;

use UniversalTelegram\Audit\AuditLogger;
use UniversalTelegram\Events\EventDispatcher;
use UniversalTelegram\Events\EventEmitter;
use UniversalTelegram\Events\EventEnvelope;
use UniversalTelegram\Events\EventHistoryRepository;
use UniversalTelegram\Events\Registry;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Privacy\Classification;
use UniversalTelegram\Privacy\Redactor;
use WP_UnitTestCase;

final class EventEmitterTest extends WP_UnitTestCase {

	private function registered_registry(): Registry {
		$registry = new Registry();
		$registry->register(
			'wordpress.user_registered',
			1,
			array( 'subject.user_id' => Classification::PUBLIC ),
			array( 'subject.user_id' ),
			array( 'subject.user_id' )
		);

		return $registry;
	}

	private function real_dispatcher( Registry $registry ): EventDispatcher {
		$history = new EventHistoryRepository( new SchemaHealth(), $registry, new Redactor() );

		return new EventDispatcher( $history );
	}

	public function test_a_downstream_exception_never_propagates_out_of_emit(): void {
		$registry   = $this->registered_registry();
		$history    = new EventHistoryRepository( new SchemaHealth(), $registry, new Redactor() );
		$dispatcher = new class( $history ) extends EventDispatcher {
			public function handle( EventEnvelope $event ): void {
				throw new \RuntimeException( 'Simulated downstream failure.' );
			}
		};
		$audit   = new AuditLogger( new SchemaHealth(), new Redactor() );
		$emitter = new EventEmitter( $registry, $dispatcher, $audit );

		// No exception should escape this call.
		$emitter->emit( 'wordpress.user_registered', array( 'subject' => array( 'user_id' => 1 ) ), 'key' );

		$this->assertTrue( true );
	}

	public function test_an_unregistered_event_type_never_throws_back_to_the_caller(): void {
		$registry   = new Registry();
		$dispatcher = $this->real_dispatcher( $registry );
		$audit      = new AuditLogger( new SchemaHealth(), new Redactor() );
		$emitter    = new EventEmitter( $registry, $dispatcher, $audit );

		$emitter->emit( 'wordpress.never_registered', array(), 'key' );

		$this->assertTrue( true );
	}

	public function test_an_unclassified_field_never_throws_back_to_the_caller(): void {
		$registry   = $this->registered_registry();
		$dispatcher = $this->real_dispatcher( $registry );
		$audit      = new AuditLogger( new SchemaHealth(), new Redactor() );
		$emitter    = new EventEmitter( $registry, $dispatcher, $audit );

		$emitter->emit( 'wordpress.user_registered', array( 'subject' => array( 'unclassified' => 'x' ) ), 'key' );

		$this->assertTrue( true );
	}

	public function test_no_action_named_universal_telegram_event_is_ever_fired(): void {
		$fired = false;
		add_action(
			'universal_telegram_event',
			static function () use ( &$fired ) {
				$fired = true;
			}
		);

		$registry   = $this->registered_registry();
		$dispatcher = $this->real_dispatcher( $registry );
		$audit      = new AuditLogger( new SchemaHealth(), new Redactor() );
		$emitter    = new EventEmitter( $registry, $dispatcher, $audit );

		$emitter->emit( 'wordpress.user_registered', array( 'subject' => array( 'user_id' => 1 ) ), 'key' );

		$this->assertFalse( $fired );
		$this->assertSame( 0, did_action( 'universal_telegram_event' ) );
	}
}
