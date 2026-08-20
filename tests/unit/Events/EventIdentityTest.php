<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Events;

use PHPUnit\Framework\TestCase;
use UniversalTelegram\Events\EventIdentity;

final class EventIdentityTest extends TestCase {

	public function test_the_same_inputs_always_derive_the_same_event_id(): void {
		$first  = EventIdentity::derive( 'wordpress.post_published', 1, 'post:17:publish' );
		$second = EventIdentity::derive( 'wordpress.post_published', 1, 'post:17:publish' );

		$this->assertSame( $first, $second );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $first );
	}

	public function test_a_different_idempotency_key_derives_a_different_event_id(): void {
		$first  = EventIdentity::derive( 'wordpress.post_published', 1, 'post:17:publish' );
		$second = EventIdentity::derive( 'wordpress.post_published', 1, 'post:18:publish' );

		$this->assertNotSame( $first, $second );
	}

	public function test_a_different_event_type_derives_a_different_event_id_for_the_same_key(): void {
		$first  = EventIdentity::derive( 'wordpress.post_published', 1, 'same-key' );
		$second = EventIdentity::derive( 'wordpress.comment_submitted', 1, 'same-key' );

		$this->assertNotSame( $first, $second );
	}

	public function test_a_different_schema_version_derives_a_different_event_id_for_the_same_key(): void {
		$first  = EventIdentity::derive( 'wordpress.post_published', 1, 'same-key' );
		$second = EventIdentity::derive( 'wordpress.post_published', 2, 'same-key' );

		$this->assertNotSame( $first, $second );
	}

	public function test_the_unit_separator_byte_prevents_a_concatenation_collision(): void {
		// Without the \x1f separator, ("a", 1, "23") and ("a1", 2, "3")
		// would both concatenate to the raw bytes "a123".
		$first  = EventIdentity::derive( 'a', 1, '23' );
		$second = EventIdentity::derive( 'a1', 2, '3' );

		$this->assertNotSame( $first, $second );
	}
}
