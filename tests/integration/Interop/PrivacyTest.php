<?php
/**
 * Item 10: no plaintext transcript/message body persisted in the wrong
 * plugin's DB/logs/audit/error output.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Tests\Integration\Interop;

final class PrivacyTest extends InteropTestCase {

	private const SECRET_MARKER = 'SC-TO-UT-PLAINTEXT-MARKER-0e9f21';
	private const REPLY_MARKER  = 'UT-TO-SC-PLAINTEXT-MARKER-7ab310';

	/**
	 * A message delivered SC -> UT must never leave its plaintext body
	 * sitting anywhere in SC's own long-term storage (audit log, conversation
	 * messages table, options) — SC only ever holds it in memory for the
	 * outbound call.
	 */
	public function test_sc_to_ut_delivered_body_never_persisted_in_sc(): void {
		$conversation_uuid = wp_generate_uuid4();
		$ref               = $this->ensure_ut_channel_case( $conversation_uuid );

		$result = $this->sc_outbound_client->deliver_message( 'universal-telegram', $ref, wp_generate_uuid4(), self::SECRET_MARKER, 'Operator' );
		self::assertTrue( $result['ok'] );

		global $wpdb;
		foreach ( array( 'universal_support_chat_audit_log', 'universal_support_chat_conversation_messages', 'universal_support_chat_conversation_notes' ) as $table ) {
			$full = $wpdb->prefix . $table;
			$hit  = $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$full} WHERE " . $this->any_text_column_like( $full ) . ' LIKE %s', '%' . self::SECRET_MARKER . '%' ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed test table/column names.
			);
			self::assertSame( '0', (string) $hit, "SC table {$table} must never contain the delivered plaintext body." );
		}
	}

	/**
	 * A reply ingested UT -> SC must never leave its plaintext body sitting
	 * in UT's own error log or audit surfaces (it is SC's own message store
	 * that legitimately holds it, encrypted at rest).
	 */
	public function test_ut_to_sc_ingested_body_never_appears_in_ut_audit(): void {
		$uuid   = $this->create_sc_conversation();
		$result = $this->ut_outbound_client->ingest_operator_reply( $uuid, 'privacy-ingest-1', self::REPLY_MARKER, 3 );
		self::assertTrue( $result['ok'] );

		global $wpdb;
		$table  = $wpdb->prefix . 'universal_telegram_audit_log';
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( null === $exists ) {
			self::markTestSkipped( 'universal_telegram_audit_log table not present in this schema version.' );
		}

		$hit = $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE context LIKE %s", '%' . self::REPLY_MARKER . '%' ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		self::assertSame( '0', (string) $hit );
	}

	/**
	 * SC's own conversation_messages table DOES legitimately store message
	 * bodies (its own domain data, encrypted at rest) — this test only
	 * proves the *encrypted column* never contains the plaintext marker
	 * verbatim, i.e. it really is ciphertext, not a plaintext leak.
	 */
	public function test_sc_conversation_message_body_column_is_ciphertext_not_plaintext(): void {
		$uuid = $this->create_sc_conversation();
		self::assertTrue( $this->ut_outbound_client->ingest_operator_reply( $uuid, 'privacy-cipher-1', self::REPLY_MARKER, 3 )['ok'] );

		$conversation = $this->sc_conversations->find_by_uuid( $uuid );
		self::assertNotNull( $conversation );

		global $wpdb;
		$table      = $wpdb->prefix . 'universal_support_chat_conversation_messages';
		$ciphertext = $wpdb->get_var(
			$wpdb->prepare( "SELECT body_ciphertext FROM {$table} WHERE conversation_id = %d ORDER BY id DESC LIMIT 1", $conversation->id() ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		self::assertIsString( $ciphertext );
		self::assertStringNotContainsString( self::REPLY_MARKER, $ciphertext, 'The stored column must be ciphertext, not the plaintext body.' );
	}

	/**
	 * Picks a text-bearing column to search for the marker string, without
	 * assuming a specific column name across tables (test-only helper).
	 */
	private function any_text_column_like( string $full_table ): string {
		global $wpdb;
		$cols = (array) $wpdb->get_col( "SHOW COLUMNS FROM {$full_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $cols as $col ) {
			if ( is_string( $col ) && ( false !== stripos( $col, 'context' ) || false !== stripos( $col, 'body' ) || false !== stripos( $col, 'text' ) || false !== stripos( $col, 'note' ) ) ) {
				return "`{$col}`";
			}
		}
		// Fallback: concatenate every column so the LIKE still runs safely.
		return "CONCAT_WS('|', " . implode( ',', array_map( static fn( $c ) => "`{$c}`", $cols ) ) . ')';
	}
}
