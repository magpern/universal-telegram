<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Events\Emitters;

use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;
use UniversalTelegram\Persistence\Migrator;
use WP_UnitTestCase;

final class LoginEmitterTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();

		// The test bootstrap loads the plugin as an MU-plugin, bypassing
		// WordPress' real activation flow, so the capability Activator
		// would normally grant is never actually granted here.
		( new CapabilityRegistrar() )->grant_to_administrator();
	}

	private function recent_event_types(): array {
		global $wpdb;

		$table = $wpdb->prefix . Migrator::EVENT_HISTORY_TABLE;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_col( "SELECT event_type FROM {$table} ORDER BY id DESC LIMIT 10" );
	}

	public function test_login_succeeded_and_admin_login_are_both_emitted_for_an_administrator(): void {
		$user = self::factory()->user->create_and_get( array( 'role' => 'administrator' ) );

		do_action( 'wp_login', $user->user_login, $user );

		$types = $this->recent_event_types();
		$this->assertContains( 'wordpress.login_succeeded', $types );
		$this->assertContains( 'wordpress.admin_login', $types );
	}

	public function test_admin_login_is_not_emitted_for_a_non_administrator(): void {
		global $wpdb;

		$table = $wpdb->prefix . Migrator::EVENT_HISTORY_TABLE;
		$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		$user = self::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );

		do_action( 'wp_login', $user->user_login, $user );

		$types = $this->recent_event_types();
		$this->assertContains( 'wordpress.login_succeeded', $types );
		$this->assertNotContains( 'wordpress.admin_login', $types );
	}

	public function test_login_failed_is_emitted(): void {
		do_action( 'wp_login_failed', 'no-such-user', null );

		$this->assertContains( 'wordpress.login_failed', $this->recent_event_types() );
	}

	public function test_two_login_failed_firings_are_independent_occurrences(): void {
		global $wpdb;

		$table = $wpdb->prefix . Migrator::EVENT_HISTORY_TABLE;
		$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		do_action( 'wp_login_failed', 'no-such-user', null );
		do_action( 'wp_login_failed', 'no-such-user', null );

		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE event_type = 'wordpress.login_failed'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$this->assertSame( 2, $count );
	}
}
