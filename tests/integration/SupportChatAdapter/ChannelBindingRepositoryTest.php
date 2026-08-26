<?php
/**
 * Integration tests for Support Chat adapter bindings and structural guards.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Tests\Integration\SupportChatAdapter;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;
use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\SupportChatAdapter\ChannelBindingRepository;
use UniversalTelegram\SupportChatAdapter\DiscoveryClient;
use UniversalTelegram\SupportChatAdapter\AdapterAvailability;
use WP_UnitTestCase;

/**
 * @covers \UniversalTelegram\SupportChatAdapter\ChannelBindingRepository
 */
final class ChannelBindingRepositoryTest extends WP_UnitTestCase {

	public function test_create_and_unique_conversation(): void {
		$repo = new ChannelBindingRepository( new SchemaHealth() );

		$first = $repo->create(
			'11111111-1111-1111-1111-111111111111',
			'22222222-2222-2222-2222-222222222222',
			'ensure-key-1',
			1,
			10,
			1001
		);
		$this->assertNotNull( $first );

		$dup = $repo->create(
			'33333333-3333-3333-3333-333333333333',
			'22222222-2222-2222-2222-222222222222',
			'ensure-key-2',
			1,
			11,
			1002
		);
		$this->assertNull( $dup );

		$by_key = $repo->find_by_ensure_key( 'ensure-key-1' );
		$this->assertNotNull( $by_key );
		$this->assertSame( '11111111-1111-1111-1111-111111111111', $by_key->binding_uuid() );
	}

	public function test_discovery_unavailable_when_sc_absent(): void {
		$client = new DiscoveryClient();
		// Without Support Chat routes registered, discovery must fail closed.
		$this->assertSame( AdapterAvailability::Unavailable, $client->resolve( true ) );
	}

	public function test_no_support_chat_sql_in_adapter_sources(): void {
		$root = dirname( __DIR__, 3 ) . '/src/SupportChatAdapter';
		$this->assertDirectoryExists( $root );

		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root ) );
		$files    = new RegexIterator( $iterator, '/\.php$/' );

		foreach ( $files as $file ) {
			/** @var \SplFileInfo $file */
			$contents = file_get_contents( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local repository source file, never a remote URL.
			$this->assertIsString( $contents );
			$this->assertDoesNotMatchRegularExpression(
				'/FROM\s+[\'"`]?\{?\$wpdb->prefix\}?[\'"`]?\s*\.?\s*[\'"]universal_support_chat_/i',
				$contents,
				'Adapter sources must not SQL Support Chat tables: ' . $file->getPathname()
			);
			$this->assertDoesNotMatchRegularExpression(
				'/(INTO|UPDATE|JOIN|TABLE)\s+[\'"`a-z0-9_\{$->]*universal_support_chat_/i',
				$contents,
				'Adapter sources must not SQL Support Chat tables: ' . $file->getPathname()
			);
		}
	}

	public function test_binding_tables_exist_after_migration(): void {
		global $wpdb;

		$bindings = $wpdb->prefix . Migrator::SUPPORT_CHAT_BINDINGS_TABLE;
		$keys     = $wpdb->prefix . Migrator::SUPPORT_CHAT_DELIVERY_KEYS_TABLE;

		$this->assertSame( $bindings, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $bindings ) ) );
		$this->assertSame( $keys, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $keys ) ) );
		$this->assertSame( '33', (string) get_option( 'universal_telegram_db_version' ) );
	}
}
