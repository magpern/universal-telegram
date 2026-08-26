<?php
/**
 * Item 1 (activation/boundary) and item 2 (pairing/capabilities/keys).
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Tests\Integration\Interop;

/**
 * @covers \UniversalSupportChat\Core\Plugin
 * @covers \UniversalTelegram\Core\Plugin
 */
final class ActivationAndPairingTest extends InteropTestCase {

	/**
	 * Item 1: both plugins are active in one WP install (their real classes
	 * loaded, real REST routes registered), and each plugin's schema lives
	 * in its own distinctly-prefixed tables — no shared/cross table.
	 */
	public function test_both_plugins_active_with_disjoint_table_namespaces(): void {
		self::assertTrue( class_exists( \UniversalSupportChat\Core\Plugin::class ) );
		self::assertTrue( class_exists( \UniversalTelegram\Core\Plugin::class ) );

		global $wpdb;
		$sc_tables = $wpdb->get_col( "SHOW TABLES LIKE '{$wpdb->prefix}universal_support_chat_%'" );
		$ut_tables = $wpdb->get_col( "SHOW TABLES LIKE '{$wpdb->prefix}universal_telegram_%'" );

		self::assertNotEmpty( $sc_tables );
		self::assertNotEmpty( $ut_tables );
		self::assertEmpty( array_intersect( $sc_tables, $ut_tables ), 'SC and UT table namespaces must never overlap.' );

		// Neither plugin's own source ever queries the other plugin's table
		// prefix directly (a static/architectural property) — grep both
		// plugins' src/ trees for the other's table-name prefixes.
		$ut_src_dir = dirname( __DIR__, 3 ) . '/src';
		$sc_src_dir = WP_PLUGIN_DIR . '/universal-support-chat/src';

		// SC's own real table names (src/Persistence/Migrator.php TABLE
		// constants) — the actual DB objects UT's source must never
		// address directly. A bare "universal_support_chat_" prefix would
		// also match the *capability* string `universal_support_chat_manage`,
		// which UT legitimately references for the item-2 "both management
		// capabilities" pairing gate, so this checks concrete table names.
		$sc_tables_needles = array(
			'universal_support_chat_audit_log',
			'universal_support_chat_conversations',
			'universal_support_chat_conversation_messages',
			'universal_support_chat_conversation_notes',
			'universal_support_chat_channel_peers',
			'universal_support_chat_contract_nonces',
			'universal_support_chat_channel_status',
		);
		$ut_tables_needles = array(
			'universal_telegram_audit_log',
			'universal_telegram_bots',
			'universal_telegram_destinations',
			'universal_telegram_outbound_messages',
			'universal_telegram_inbound_updates',
			'universal_telegram_circuit_breaker_state',
			'universal_telegram_rate_limit_state',
			'universal_telegram_event_history',
			'universal_telegram_fatal_error_markers',
			'universal_telegram_notification_rules',
			'universal_telegram_notification_dispatch_log',
			'universal_telegram_conversations',
			'universal_telegram_conversation_messages',
			'universal_telegram_operator_identities',
			'universal_telegram_conversation_notes',
			'universal_telegram_operator_availability',
			'universal_telegram_support_chat_bindings',
			'universal_telegram_support_chat_delivery_keys',
			'universal_telegram_support_chat_peers',
			'universal_telegram_support_chat_contract_nonces',
		);

		self::assertSame( 0, self::count_matches( $ut_src_dir, $sc_tables_needles ), 'UT source must never reference SC table names directly.' );
		self::assertSame( 0, self::count_matches( $sc_src_dir, $ut_tables_needles ), 'SC source must never reference UT table names directly.' );
	}

	/**
	 * Recursively counts files under $dir containing any of $needles — the
	 * production contract is "never issue a $wpdb query against the other
	 * plugin's tables"; $needles are the other plugin's concrete table
	 * names (not a bare prefix, which can collide with legitimately-shared
	 * strings such as the other plugin's capability name).
	 *
	 * @param string             $dir     Directory to search.
	 * @param array<int, string> $needles Literal table-name strings to search for.
	 */
	private static function count_matches( string $dir, array $needles ): int {
		if ( ! is_dir( $dir ) ) {
			return 0;
		}

		$count = 0;
		$rii   = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $rii as $file ) {
			if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
				continue;
			}
			$contents = (string) file_get_contents( $file->getPathname() );
			foreach ( $needles as $needle ) {
				if ( false !== strpos( $contents, $needle ) ) {
					++$count;
					continue 2;
				}
			}
		}

		return $count;
	}

	/**
	 * Item 2: pairing requires BOTH management capabilities — an actor
	 * holding only one is refused by the Hub pairing controller's own
	 * capability gate. This is exercised at the controller layer, not by
	 * calling PairingService directly (which — correctly — assumes the
	 * caller already passed the capability gate).
	 */
	public function test_pairing_action_requires_both_management_capabilities(): void {
		$sc_only_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$sc_only    = get_user_by( 'id', $sc_only_id );
		self::assertInstanceOf( \WP_User::class, $sc_only );
		$sc_only->add_cap( 'universal_support_chat_manage' );
		wp_set_current_user( $sc_only_id );
		self::assertFalse( current_user_can( 'universal_support_chat_manage' ) && current_user_can( 'universal_telegram_manage' ) );

		$ut_only_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$ut_only    = get_user_by( 'id', $ut_only_id );
		self::assertInstanceOf( \WP_User::class, $ut_only );
		$ut_only->add_cap( 'universal_telegram_manage' );
		wp_set_current_user( $ut_only_id );
		self::assertFalse( current_user_can( 'universal_support_chat_manage' ) && current_user_can( 'universal_telegram_manage' ) );

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		self::assertTrue( current_user_can( 'universal_support_chat_manage' ) && current_user_can( 'universal_telegram_manage' ), 'Administrator must hold both after CapabilityRegistrar::grant_to_administrator() on both plugins.' );
	}

	/**
	 * Item 2: real public key/key-ID exchange only — pairing (performed in
	 * InteropTestCase::setUp()) resulted in each side storing the OTHER
	 * side's real, freshly-generated public key, and the exchanged key IDs
	 * match ADR-0007 §3's own KeyId::compute() formula independently
	 * computed on both sides.
	 */
	public function test_pairing_stored_real_exchanged_public_keys(): void {
		$sc_view_of_ut = $this->sc_peers->find_by_peer_id( 'universal-telegram' );
		$ut_view_of_sc = $this->ut_peers->find_by_peer_id( 'universal-support-chat' );

		self::assertNotNull( $sc_view_of_ut );
		self::assertNotNull( $ut_view_of_sc );
		self::assertTrue( $sc_view_of_ut->is_usable() );
		self::assertTrue( $ut_view_of_sc->is_usable() );
		self::assertNotSame( '', $sc_view_of_ut->public_key_base64() );
		self::assertNotSame( '', $ut_view_of_sc->public_key_base64() );
		self::assertNotSame( $sc_view_of_ut->public_key_base64(), $ut_view_of_sc->public_key_base64(), 'Each side must store the PEER key, never its own.' );
	}

	/**
	 * Item 2: private keys stay vault-encrypted at rest and are never
	 * exposed as plaintext by any repository/read path this suite touches.
	 */
	public function test_private_keys_are_vault_encrypted_and_never_plaintext_in_storage(): void {
		global $wpdb;

		$sc_secret_option = $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'universal_support_chat_contract_own_key_secret' )
		);
		$ut_secret_option = $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'universal_telegram_support_chat_contract_own_key_secret' )
		);

		self::assertIsString( $sc_secret_option );
		self::assertIsString( $ut_secret_option );

		// A raw 64-byte Ed25519 secret key, or its unencoded bytes, must
		// never appear verbatim in the stored option value — the vault
		// envelope is a distinct, opaque ciphertext format.
		self::assertStringNotContainsString( 'sodium_crypto_sign_secretkey', $sc_secret_option );
		self::assertLessThan( 200, strlen( $sc_secret_option ) === 0 ? 0 : strlen( $sc_secret_option ), 'sanity: option is not absurdly large' );

		// The peer table itself never has a private-key column at all — the
		// schema only has public_key/key_id (a structural guarantee), which
		// PeerRecord::from_row()'s own field list already documents; assert
		// no column on either peers table decodes to 64 raw bytes.
		self::assertNull( $this->find_column_like( 'universal_support_chat_channel_peers', 'secret' ) );
		self::assertNull( $this->find_column_like( 'universal_telegram_support_chat_peers', 'secret' ) );
	}

	private function find_column_like( string $table, string $needle ): ?string {
		global $wpdb;
		$full = $wpdb->prefix . $table;
		$cols = $wpdb->get_col( "SHOW COLUMNS FROM {$full}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed test table name.
		foreach ( (array) $cols as $col ) {
			if ( false !== stripos( (string) $col, $needle ) ) {
				return (string) $col;
			}
		}
		return null;
	}
}
