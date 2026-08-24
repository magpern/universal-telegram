<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Automations\Intelligence;

use PHPUnit\Framework\TestCase;

/**
 * M11B plan §3/§8 decision 4: SummaryAiRepository is referenced only by a
 * fixed five-class allow-list, and no class in its own AI-summary code
 * path ever references Telegram\Outbound\MessageDispatcher — the
 * structural no-auto-send guarantee. Mirrors
 * AiDraftRepositoryAccessAllowListTest's exact static-scan technique.
 */
final class SummaryAiRepositoryAccessAllowListTest extends TestCase {

	/**
	 * Repository-relative paths, exactly the five classes the frozen plan
	 * names.
	 *
	 * @var array<int, string>
	 */
	private const ALLOWED_FILES = array(
		'src/Automations/Intelligence/SummaryAiRepository.php',
		'src/Automations/Intelligence/SummaryAiRequestHandler.php',
		'src/Automations/Intelligence/SummaryAiGenerationHandler.php',
		'src/Automations/Intelligence/SummaryAiLeaseSweep.php',
		'src/Administration/Automations/IntelligencePanel.php',
	);

	/**
	 * Files whose own AI-summary generation/request code path must never
	 * reference Telegram\Outbound\MessageDispatcher — the structural
	 * no-auto-send guarantee.
	 *
	 * @var array<int, string>
	 */
	private const NO_MESSAGE_DISPATCHER_FILES = array(
		'src/Automations/Intelligence/SummaryAiRequestHandler.php',
		'src/Automations/Intelligence/SummaryAiGenerationHandler.php',
		'src/Automations/Intelligence/SummaryAiLeaseSweep.php',
	);

	public function test_only_the_fixed_five_class_allow_list_references_summary_ai_repository(): void {
		$src_root = dirname( __DIR__, 4 ) . '/src';

		$referencing_files = array( 'src/Automations/Intelligence/SummaryAiRepository.php' );

		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $src_root, \FilesystemIterator::SKIP_DOTS ) );

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
				continue;
			}

			if ( str_ends_with( $file->getPathname(), 'SummaryAiRepository.php' ) ) {
				continue;
			}

			// The composition root necessarily constructs and wires every
			// service in the plugin — excluded for the same reason
			// AiDraftRepositoryAccessAllowListTest excludes it.
			if ( str_ends_with( $file->getPathname(), '/Core/Plugin.php' ) ) {
				continue;
			}

			$contents = file_get_contents( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

			if ( false === $contents ) {
				continue;
			}

			$references_import      = false !== strpos( $contents, 'use UniversalTelegram\\Automations\\Intelligence\\SummaryAiRepository;' );
			$references_type_hint   = (bool) preg_match( '/\bSummaryAiRepository\s+\$\w+/', $contents );
			$references_instantiate = false !== strpos( $contents, 'new SummaryAiRepository(' );

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
			'SummaryAiRepository must be referenced only by the fixed five-class allow-list.'
		);
	}

	public function test_no_ai_summary_class_references_message_dispatcher(): void {
		$repo_root = dirname( __DIR__, 4 );

		foreach ( self::NO_MESSAGE_DISPATCHER_FILES as $relative ) {
			$path     = $repo_root . '/' . $relative;
			$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

			$this->assertStringNotContainsString(
				'MessageDispatcher',
				false === $contents ? '' : $contents,
				"{$relative} must never reference MessageDispatcher — AI-summary content is never auto-sent."
			);
		}
	}
}
