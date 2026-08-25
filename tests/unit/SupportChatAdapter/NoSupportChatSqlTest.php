<?php
/**
 * Structural proof that the adapter never writes Support Chat's own SQL.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Tests\SupportChatAdapter;

use PHPUnit\Framework\TestCase;

/**
 * This plugin persists only its own tables (Persistence\Migrator::*_TABLE
 * constants) and never references Support Chat's internal table/option
 * names or issues a raw query against them. Contract v1 calls cross the
 * boundary exclusively via signed HTTP (SupportChatContractClient) and the
 * verified REST acceptors (OutboundContractController) — never SQL.
 */
final class NoSupportChatSqlTest extends TestCase {

	/**
	 * Support Chat's own known internal table/prefix names (from its own
	 * repository's Migrator), which must never appear in this plugin's
	 * source as a literal SQL identifier.
	 *
	 * @var array<int, string>
	 */
	private const FORBIDDEN_IDENTIFIERS = array(
		'universal_support_chat_conversations',
		'universal_support_chat_messages',
		'universal_support_chat_channel_peers',
		'universal_support_chat_contract_nonces',
	);

	public function test_adapter_source_never_references_support_chat_tables(): void {
		$root  = dirname( __DIR__, 3 ) . '/src/SupportChatAdapter';
		$files = $this->php_files( $root );

		$this->assertNotEmpty( $files, 'Expected to find SupportChatAdapter source files.' );

		foreach ( $files as $file ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local filesystem read in a test, not a remote URL.
			$contents = (string) file_get_contents( $file );

			foreach ( self::FORBIDDEN_IDENTIFIERS as $identifier ) {
				$this->assertStringNotContainsString(
					$identifier,
					$contents,
					"{$file} must never reference Support Chat's own table {$identifier}."
				);
			}
		}
	}

	public function test_adapter_source_never_issues_raw_wpdb_query_against_a_non_prefixed_literal_table(): void {
		$root  = dirname( __DIR__, 3 ) . '/src/SupportChatAdapter';
		$files = $this->php_files( $root );

		$inspected = 0;

		foreach ( $files as $file ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local filesystem read in a test, not a remote URL.
			$contents = (string) file_get_contents( $file );

			// Only files that actually touch $wpdb are relevant — this
			// excludes admin UI/prose files (e.g. Pairing/PairingController)
			// whose English text can otherwise coincidentally contain the
			// word "from".
			if ( ! str_contains( $contents, '$wpdb' ) ) {
				continue;
			}

			++$inspected;

			// Every SQL FROM clause in this subtree must be built from an
			// interpolated PHP variable (`{$table}` etc.), never a bare
			// literal identifier — this plugin's own tables are always
			// referenced via $wpdb->prefix . a Migrator::*_TABLE constant.
			if ( preg_match( '/FROM\s+(?!\{\$)[a-zA-Z_][a-zA-Z0-9_]*\b/', $contents, $matches ) === 1 ) {
				$this->fail( "{$file} appears to reference a literal (non-interpolated) table name: {$matches[0]}" );
			}
		}

		$this->assertGreaterThan( 0, $inspected, 'Expected at least one $wpdb-touching file in SupportChatAdapter.' );
	}

	/**
	 * @return array<int, string>
	 */
	private function php_files( string $root ): array {
		$found = array();

		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file_info ) {
			if ( $file_info->isFile() && 'php' === $file_info->getExtension() ) {
				$found[] = $file_info->getPathname();
			}
		}

		return $found;
	}
}
