<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Events;

use PHPUnit\Framework\TestCase;
use UniversalTelegram\Events\EventTypeAlreadyRegisteredException;
use UniversalTelegram\Events\NonPublicHistoryFieldException;
use UniversalTelegram\Events\Registry;
use UniversalTelegram\Events\UnclassifiedFieldException;
use UniversalTelegram\Privacy\Classification;

final class RegistryTest extends TestCase {

	public function test_a_registered_event_type_is_reported_registered_with_its_own_data(): void {
		$registry = new Registry();
		$registry->register(
			'wordpress.user_registered',
			1,
			array( 'subject.user_id' => Classification::PUBLIC ),
			array( 'subject.user_id' ),
			array( 'subject.user_id' )
		);

		$this->assertTrue( $registry->is_registered( 'wordpress.user_registered' ) );
		$this->assertSame( 1, $registry->schema_version_for( 'wordpress.user_registered' ) );
		$this->assertSame( array( 'subject.user_id' ), $registry->allowed_variable_fields_for( 'wordpress.user_registered' ) );
		$this->assertSame( array( 'subject.user_id' ), $registry->history_projection_fields_for( 'wordpress.user_registered' ) );
	}

	public function test_an_unregistered_event_type_is_reported_unregistered(): void {
		$registry = new Registry();

		$this->assertFalse( $registry->is_registered( 'wordpress.never_registered' ) );
		$this->assertNull( $registry->schema_version_for( 'wordpress.never_registered' ) );
		$this->assertSame( array(), $registry->classification_map_for( 'wordpress.never_registered' ) );
	}

	public function test_registering_the_same_event_type_twice_throws(): void {
		$registry = new Registry();
		$registry->register( 'wordpress.user_registered', 1, array(), array(), array() );

		$this->expectException( EventTypeAlreadyRegisteredException::class );
		$registry->register( 'wordpress.user_registered', 1, array(), array(), array() );
	}

	public function test_an_allowed_variable_field_not_in_the_classification_map_throws(): void {
		$registry = new Registry();

		$this->expectException( UnclassifiedFieldException::class );
		$registry->register( 'wordpress.user_registered', 1, array(), array( 'subject.user_id' ), array() );
	}

	public function test_a_history_projection_field_not_in_the_classification_map_throws(): void {
		$registry = new Registry();

		$this->expectException( UnclassifiedFieldException::class );
		$registry->register( 'wordpress.user_registered', 1, array(), array(), array( 'subject.user_id' ) );
	}

	public function test_an_internal_classified_field_in_history_projection_fields_throws(): void {
		$registry = new Registry();

		$this->expectException( NonPublicHistoryFieldException::class );
		$registry->register(
			'wordpress.user_registered',
			1,
			array( 'subject.email' => Classification::INTERNAL ),
			array( 'subject.email' ),
			array( 'subject.email' )
		);
	}

	public function test_an_internal_field_is_permitted_as_an_allowed_variable_field_but_not_history(): void {
		$registry = new Registry();
		$registry->register(
			'wordpress.user_registered',
			1,
			array(
				'subject.user_id' => Classification::PUBLIC,
				'subject.email'   => Classification::INTERNAL,
			),
			array( 'subject.user_id', 'subject.email' ),
			array( 'subject.user_id' )
		);

		$this->assertContains( 'subject.email', $registry->allowed_variable_fields_for( 'wordpress.user_registered' ) );
		$this->assertNotContains( 'subject.email', $registry->history_projection_fields_for( 'wordpress.user_registered' ) );
	}

	public function test_all_lists_every_registered_event_type(): void {
		$registry = new Registry();
		$registry->register( 'wordpress.user_registered', 1, array(), array(), array() );
		$registry->register( 'wordpress.login_failed', 1, array(), array(), array() );

		$types = array_column( $registry->all(), 'event_type' );

		$this->assertSame( array( 'wordpress.user_registered', 'wordpress.login_failed' ), $types );
	}
}
