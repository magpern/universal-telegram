<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Events\Emitters;

use UniversalTelegram\Persistence\Migrator;
use WP_Error;
use WP_REST_Request;
use WP_UnitTestCase;

final class RestRequestFailureEmitterTest extends WP_UnitTestCase {

	private function count_for( string $event_type ): int {
		global $wpdb;

		$table = $wpdb->prefix . Migrator::EVENT_HISTORY_TABLE;

		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE event_type = %s", $event_type ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	public function test_a_failure_on_another_namespace_is_emitted(): void {
		$request = new WP_REST_Request( 'GET', '/wp/v2/posts' );
		$error   = new WP_Error( 'rest_forbidden', 'Forbidden', array( 'status' => 403 ) );

		apply_filters( 'rest_request_after_callbacks', $error, array(), $request );

		$this->assertSame( 1, $this->count_for( 'wordpress.rest_request_failed' ) );
	}

	public function test_excludes_own_rest_namespace(): void {
		$request = new WP_REST_Request( 'POST', '/universal-telegram/v1/webhook/some-uuid' );
		$error   = new WP_Error( 'rest_forbidden', 'Forbidden', array( 'status' => 401 ) );

		apply_filters( 'rest_request_after_callbacks', $error, array(), $request );

		$this->assertSame( 0, $this->count_for( 'wordpress.rest_request_failed' ) );
	}

	public function test_a_successful_response_is_never_treated_as_a_failure(): void {
		$request  = new WP_REST_Request( 'GET', '/wp/v2/posts' );
		$response = array( 'ok' => true );

		$result = apply_filters( 'rest_request_after_callbacks', $response, array(), $request );

		$this->assertSame( $response, $result );
		$this->assertSame( 0, $this->count_for( 'wordpress.rest_request_failed' ) );
	}
}
