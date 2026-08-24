<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Events\Emitters;

use UniversalTelegram\Core\Plugin;
use UniversalTelegram\Events\Emitters\UserLifecycleEmitter;
use UniversalTelegram\Events\Registry;
use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Privacy\Classification;
use UniversalTelegram\Telegram\Configuration\BotStatus;
use UniversalTelegram\Telegram\Configuration\DestinationKind;
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
		// Username/name/email are INTERNAL (not PUBLIC): they must never
		// reach the durable PUBLIC-only event history projection, even
		// though they are usable in a rule's own condition/message.
		$this->assertSame( array( 'subject' => array( 'user_id' => $user_id ) ), json_decode( $row['projected_fields_json'], true ) );
	}

	public function test_username_name_and_email_are_registered_internal_and_usable_in_templates(): void {
		$registry = new Registry();
		( new UserLifecycleEmitter() )->register_event_types( $registry );

		$allowed = $registry->allowed_variable_fields_for( 'wordpress.user_registered' );
		$this->assertContains( 'subject.username', $allowed );
		$this->assertContains( 'subject.name', $allowed );
		$this->assertContains( 'subject.email', $allowed );
		$this->assertContains( 'subject.country', $allowed );
		$this->assertContains( 'subject.region', $allowed );

		$classification = $registry->classification_map_for( 'wordpress.user_registered' );
		$this->assertSame( Classification::INTERNAL, $classification['subject.username'] );
		$this->assertSame( Classification::INTERNAL, $classification['subject.name'] );
		$this->assertSame( Classification::INTERNAL, $classification['subject.email'] );
		$this->assertSame( Classification::INTERNAL, $classification['subject.country'] );
		$this->assertSame( Classification::INTERNAL, $classification['subject.region'] );

		$this->assertSame( array( 'subject.user_id' ), $registry->history_projection_fields_for( 'wordpress.user_registered' ) );
	}

	public function test_country_and_region_are_silently_absent_when_universal_geo_context_is_not_active(): void {
		$this->assertFalse(
			function_exists( 'universal_geo_get_country_code' ),
			'This test assumes Universal Geo Context is not loaded in this test run; if it now is, the emitter\'s guarded fallback path is no longer exercised here.'
		);

		$plugin      = Plugin::instance();
		$bot         = $plugin->bot_profile_repository()->create( 'Bot', str_repeat( 'b', 46 ) );
		$destination = $plugin->destination_repository()->create( $bot->id(), DestinationKind::PRIVATE, '556', null, 'Ops' );
		$plugin->bot_profile_repository()->set_status( $bot->id(), BotStatus::ACTIVE );

		$plugin->notification_rule_repository()->save(
			null,
			'Geo rule',
			'wordpress.user_registered',
			1,
			array(),
			$bot->id(),
			$destination->id(),
			'Country: [{{subject.country}}]',
			true,
			100,
			0,
			'all'
		);

		$user_id = self::factory()->user->create();

		do_action( 'user_register', $user_id );

		global $wpdb;
		$table = $wpdb->prefix . Migrator::OUTBOUND_MESSAGES_TABLE;
		$row   = $wpdb->get_row( "SELECT * FROM {$table} ORDER BY id DESC LIMIT 1", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$this->assertNotNull( $row );

		$message = $plugin->outbound_message_repository()->find( (int) $row['id'] );
		$body    = $plugin->outbound_message_repository()->decrypt_body( $message );

		// The literal "[" and "]" are MarkdownV2-escaped; the token itself
		// resolves to an empty string, never a raw placeholder or a fatal.
		$this->assertStringContainsString( 'Country: \\[\\]', (string) $body->plaintext() );
	}

	public function test_username_and_email_reach_a_matched_rules_queued_outbound_message(): void {
		global $wpdb;

		$plugin = Plugin::instance();
		$bot    = $plugin->bot_profile_repository()->create( 'Bot', str_repeat( 'a', 46 ) );
		$this->assertNotNull( $bot );
		$plugin->bot_profile_repository()->set_status( $bot->id(), BotStatus::ACTIVE );

		$destination = $plugin->destination_repository()->create( $bot->id(), DestinationKind::PRIVATE, '555', null, 'Ops' );
		$this->assertNotNull( $destination );

		$plugin->notification_rule_repository()->save(
			null,
			'New user rule',
			'wordpress.user_registered',
			1,
			array(),
			$bot->id(),
			$destination->id(),
			'New user: {{subject.username}} ({{subject.email}})',
			true,
			100,
			0,
			'all'
		);

		$user_id = self::factory()->user->create(
			array(
				'user_login' => 'quinn',
				'user_email' => 'quinn@example.com',
			)
		);

		do_action( 'user_register', $user_id );

		$table = $wpdb->prefix . Migrator::OUTBOUND_MESSAGES_TABLE;
		$row   = $wpdb->get_row( "SELECT * FROM {$table} ORDER BY id DESC LIMIT 1", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$this->assertNotNull( $row );

		$message = $plugin->outbound_message_repository()->find( (int) $row['id'] );
		$this->assertNotNull( $message );

		$body = $plugin->outbound_message_repository()->decrypt_body( $message );
		$this->assertNotNull( $body );

		$text = (string) $body->plaintext();
		$this->assertStringContainsString( 'quinn', $text );
		// The literal "." in the email's domain is escaped like any other
		// literal MarkdownV2-reserved character.
		$this->assertStringContainsString( 'quinn@example\\.com', $text );
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
