<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Events\Emitters;

use UniversalTelegram\Persistence\Migrator;
use WP_Error;
use WP_UnitTestCase;

final class MailFailureEmitterTest extends WP_UnitTestCase {

	public function test_mail_failure_is_emitted_with_only_the_error_code(): void {
		global $wpdb;

		$error = new WP_Error( 'wp_mail_failed', 'Recipient address rejected: someone@example.com' );

		do_action( 'wp_mail_failed', $error );

		$table = $wpdb->prefix . Migrator::EVENT_HISTORY_TABLE;
		$row   = $wpdb->get_row(
			"SELECT * FROM {$table} WHERE event_type = 'wordpress.email_sending_failed' ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);

		$this->assertNotNull( $row );
		$this->assertStringNotContainsString( 'someone@example.com', $row['projected_fields_json'] );
		$this->assertStringNotContainsString( 'Recipient address rejected', $row['projected_fields_json'] );

		$projected = json_decode( $row['projected_fields_json'], true );
		$this->assertSame( 'wp_mail_failed', $projected['payload']['error_code'] );
	}
}
