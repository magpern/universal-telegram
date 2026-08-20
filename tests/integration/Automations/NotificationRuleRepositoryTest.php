<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Automations;

use UniversalTelegram\Automations\InvalidConditionFieldException;
use UniversalTelegram\Automations\NotificationRuleRepository;
use UniversalTelegram\Events\Registry;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Privacy\Classification;
use WP_UnitTestCase;

final class NotificationRuleRepositoryTest extends WP_UnitTestCase {

	private function registry(): Registry {
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

	public function test_save_rejects_a_condition_field_outside_the_allowlist(): void {
		$repo = new NotificationRuleRepository( new SchemaHealth(), $this->registry() );

		$this->expectException( InvalidConditionFieldException::class );
		$repo->save(
			null,
			'Test rule',
			'wordpress.user_registered',
			1,
			array( array( 'field' => 'subject.not_allowed', 'operator' => 'equals', 'value' => 'x' ) ),
			1,
			1,
			'Hello',
			true,
			100,
			0
		);
	}

	public function test_save_accepts_an_allowed_condition_field_and_round_trips(): void {
		$repo = new NotificationRuleRepository( new SchemaHealth(), $this->registry() );

		$rule = $repo->save(
			null,
			'Test rule',
			'wordpress.user_registered',
			1,
			array( array( 'field' => 'subject.user_id', 'operator' => 'equals', 'value' => 42 ) ),
			1,
			1,
			'Hello {{ subject.user_id }}',
			true,
			100,
			0
		);

		$this->assertNotNull( $rule );
		$this->assertSame( 'Test rule', $rule->name() );
		$this->assertSame( array( array( 'field' => 'subject.user_id', 'operator' => 'equals', 'value' => 42 ) ), $rule->conditions() );
	}

	public function test_for_event_type_orders_by_priority_then_id(): void {
		$registry = $this->registry();
		$repo     = new NotificationRuleRepository( new SchemaHealth(), $registry );

		$first  = $repo->save( null, 'B', 'wordpress.user_registered', 1, array(), 1, 1, 'x', true, 200, 0 );
		$second = $repo->save( null, 'A', 'wordpress.user_registered', 1, array(), 1, 1, 'x', true, 100, 0 );
		$third  = $repo->save( null, 'C', 'wordpress.user_registered', 1, array(), 1, 1, 'x', true, 100, 0 );

		$ordered = $repo->for_event_type( 'wordpress.user_registered' );
		$ids     = array_map( static fn( $rule ) => $rule->id(), $ordered );

		$this->assertSame( array( $second->id(), $third->id(), $first->id() ), $ids );
	}

	public function test_for_event_type_excludes_disabled_rules_by_default(): void {
		$registry = $this->registry();
		$repo     = new NotificationRuleRepository( new SchemaHealth(), $registry );

		$repo->save( null, 'Disabled', 'wordpress.user_registered', 1, array(), 1, 1, 'x', false, 100, 0 );
		$enabled = $repo->save( null, 'Enabled', 'wordpress.user_registered', 1, array(), 1, 1, 'x', true, 100, 0 );

		$results = $repo->for_event_type( 'wordpress.user_registered' );
		$ids     = array_map( static fn( $rule ) => $rule->id(), $results );

		$this->assertSame( array( $enabled->id() ), $ids );
	}

	public function test_delete_removes_the_rule(): void {
		$repo = new NotificationRuleRepository( new SchemaHealth(), $this->registry() );

		$rule = $repo->save( null, 'X', 'wordpress.user_registered', 1, array(), 1, 1, 'x', true, 100, 0 );
		$this->assertTrue( $repo->delete( $rule->id() ) );
		$this->assertNull( $repo->find( $rule->id() ) );
	}
}
