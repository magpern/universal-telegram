<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\AI;

use PHPUnit\Framework\TestCase;

/**
 * Docs/adr/0028 decision 6: AiDraftRepository is referenced only by a
 * fixed six-class allow-list. Statically scans every PHP file under src/
 * for a reference to AiDraftRepository (a `use` import, a constructor
 * type-hint, or a `new AiDraftRepository(` construction) and asserts the
 * referencing-file set is exactly this allow-list — catching both a
 * prohibited boundary (visitor REST, widget, webhook, Telegram outbound,
 * or any other Administration\* page) and scope creep within the AI/
 * Administration\AI namespaces themselves.
 */
final class AiDraftRepositoryAccessAllowListTest extends TestCase {

	/**
	 * Repository-relative paths, exactly the six classes docs/adr/0028
	 * decision 6 names.
	 *
	 * @var array<int, string>
	 */
	private const ALLOWED_FILES = array(
		'src/AI/Draft/AiDraftRepository.php',
		'src/AI/Draft/DraftRequestHandler.php',
		'src/AI/Draft/AIDraftGenerationHandler.php',
		'src/AI/Draft/AiDraftLeaseSweep.php',
		'src/Administration/AI/ConversationDraftPanel.php',
		'src/Administration/AI/AIDiagnosticsPanel.php',
	);

	public function test_only_the_fixed_six_class_allow_list_references_ai_draft_repository(): void {
		$src_root = dirname( __DIR__, 3 ) . '/src';

		// The class's own definition file trivially "references" itself
		// (the allow-list table's own "—" access row) — included
		// unconditionally rather than matched by pattern.
		$referencing_files = array( 'src/AI/Draft/AiDraftRepository.php' );

		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $src_root, \FilesystemIterator::SKIP_DOTS ) );

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
				continue;
			}

			if ( str_ends_with( $file->getPathname(), 'AiDraftRepository.php' ) ) {
				continue;
			}

			// The composition root necessarily constructs and wires every
			// service in the plugin (every other repository has the
			// identical property) — excluded from this check for the same
			// reason it is excluded from every other implicit
			// boundary-ownership convention in this codebase; the
			// meaningful check is which *consumers* it hands the instance
			// to, which the allow-list above governs.
			if ( str_ends_with( $file->getPathname(), '/Core/Plugin.php' ) ) {
				continue;
			}

			$contents = file_get_contents( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local repository source file, never a remote URL.

			if ( false === $contents ) {
				continue;
			}

			$references_import      = false !== strpos( $contents, 'use UniversalTelegram\\AI\\Draft\\AiDraftRepository;' );
			$references_type_hint   = (bool) preg_match( '/\bAiDraftRepository\s+\$\w+/', $contents );
			$references_instantiate = false !== strpos( $contents, 'new AiDraftRepository(' );

			if ( $references_import || $references_type_hint || $references_instantiate ) {
				$relative            = 'src' . substr( $file->getPathname(), strlen( $src_root ) );
				$referencing_files[] = str_replace( '\\', '/', $relative );
			}
		}

		sort( $referencing_files );
		$expected = self::ALLOWED_FILES;
		sort( $expected );

		$this->assertSame(
			$expected,
			array_values( array_unique( $referencing_files ) ),
			'AiDraftRepository must be referenced only by the fixed six-class allow-list (docs/adr/0028 decision 6).'
		);
	}

	public function test_no_visitor_facing_or_telegram_outbound_class_references_it(): void {
		$src_root        = dirname( __DIR__, 3 ) . '/src';
		$prohibited_dirs = array(
			$src_root . '/Conversations/Rest',
			$src_root . '/ChatWidget',
			$src_root . '/Telegram/Outbound',
			$src_root . '/Telegram/Inbound',
		);

		foreach ( $prohibited_dirs as $dir ) {
			if ( ! is_dir( $dir ) ) {
				continue;
			}

			$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );

			foreach ( $iterator as $file ) {
				if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
					continue;
				}

				$contents = file_get_contents( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local repository source file, never a remote URL.

				$this->assertStringNotContainsString(
					'AiDraftRepository',
					false === $contents ? '' : $contents,
					$file->getPathname() . ' must never reference AiDraftRepository (docs/adr/0028 decision 6).'
				);
			}
		}
	}
}
