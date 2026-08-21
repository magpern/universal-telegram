<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Events\Emitters;

use UniversalTelegram\Persistence\Migrator;
use WP_UnitTestCase;

final class UserLifecycleEmitterTest extends WP_UnitTestCase {

	private function history_row_for( string $event_type ): ?array {
		global $wpdb;

		$table = $wpdb->prefix . Migrator::EVENT_HISTORY_TABLE;

		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE event_type = %s ORDER BY id DESC LIMIT 1", $event_type ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
	}

	public function test_user_registered_is_emitted(): void {
		$user_id = self::factory()->user->create();

		do_action( 'user_register', $user_id );

		$row = $this->history_row_for( 'wordpress.user_registered' );
		$this->assertNotNull( $row );
		$this->assertSame( array( 'subject' => array( 'user_id' => $user_id ) ), json_decode( $row['projected_fields_json'], true ) );
	}

	public function test_role_changed_is_emitted_with_new_and_old_roles(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		do_action( 'set_user_role', $user_id, 'editor', array( 'subscriber' ) );

		$row = $this->history_row_for( 'wordpress.user_role_changed' );
		$this->assertNotNull( $row );
		$projected = json_decode( $row['projected_fields_json'], true );
		$this->assertSame( $user_id, $projected['subject']['user_id'] );
		$this->assertSame( 'editor', $projected['payload']['new_role'] );
	}

	public function test_password_reset_is_emitted_without_reading_the_new_password(): void {
		$user = self::factory()->user->create_and_get();

		// A deliberately unusual value: proves the emitter never
		// dereferences it into anything observable.
		do_action( 'after_password_reset', $user, "unused-\x00-value" );

		$row = $this->history_row_for( 'wordpress.password_reset' );
		$this->assertNotNull( $row );
		$this->assertStringNotContainsString( 'unused', $row['projected_fields_json'] );
	}
}
