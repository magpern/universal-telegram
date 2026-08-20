<?php
/**
 * Doc-link checker.
 *
 * Verifies every relative Markdown link in the given paths resolves to a
 * file that actually exists on disk. Dependency-free; takes one or more
 * paths as arguments, recursively walking any directory argument for .md
 * files. Exits 0 with no output if every link resolves; exits non-zero
 * with a list of unresolved links otherwise.
 *
 * A standalone CLI script, never loaded by WordPress itself.
 *
 * Usage: php bin/check-doc-links.php <path> [<path> ...]
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

/**
 * Recursively collects every .md file under a directory.
 *
 * @param string $dir The directory to walk.
 *
 * @return array<int, string>
 */
function universal_telegram_collect_markdown_files( string $dir ): array {
	$files = array();

	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS )
	);

	foreach ( $iterator as $file ) {
		if ( $file->isFile() && 'md' === strtolower( $file->getExtension() ) ) {
			$files[] = $file->getPathname();
		}
	}

	return $files;
}

/**
 * Extracts every relative (non-URL, non-absolute) Markdown link target.
 *
 * @param string $content The Markdown file's own content.
 *
 * @return array<int, string>
 */
function universal_telegram_extract_relative_links( string $content ): array {
	$links = array();

	if ( 0 === preg_match_all( '/\]\(([^)]+)\)/', $content, $matches ) ) {
		return $links;
	}

	foreach ( $matches[1] as $target ) {
		$target = trim( explode( ' ', $target, 2 )[0] );
		$target = explode( '#', $target, 2 )[0];

		if ( '' === $target ) {
			continue;
		}

		if ( 1 === preg_match( '#^([a-z][a-z0-9+.\-]*:|/)#i', $target ) ) {
			continue;
		}

		$links[] = $target;
	}

	return $links;
}

/**
 * Checks every relative Markdown link found under the given paths.
 *
 * @param array<int, string> $paths The paths passed on the command line.
 *
 * @return int A process exit code: 0 if every link resolves, 1 otherwise.
 */
function universal_telegram_check_doc_links( array $paths ): int {
	$files = array();

	foreach ( $paths as $path ) {
		if ( is_dir( $path ) ) {
			$files = array_merge( $files, universal_telegram_collect_markdown_files( $path ) );
			continue;
		}

		if ( is_file( $path ) ) {
			$files[] = $path;
		}
	}

	$unresolved = array();

	foreach ( $files as $file ) {
		$content = file_get_contents( $file );

		if ( false === $content ) {
			continue;
		}

		foreach ( universal_telegram_extract_relative_links( $content ) as $link ) {
			$resolved = dirname( $file ) . '/' . $link;

			if ( ! file_exists( $resolved ) ) {
				$unresolved[] = sprintf( '%s -> %s (resolved: %s)', $file, $link, $resolved );
			}
		}
	}

	if ( array() !== $unresolved ) {
		fwrite( STDERR, "Unresolved Markdown links:\n" );
		foreach ( $unresolved as $entry ) {
			fwrite( STDERR, '  - ' . $entry . "\n" );
		}
		return 1;
	}

	return 0;
}

$universal_telegram_doc_link_paths = array_slice( $argv, 1 );

if ( array() === $universal_telegram_doc_link_paths ) {
	fwrite( STDERR, "Usage: php bin/check-doc-links.php <path> [<path> ...]\n" );
	exit( 1 );
}

exit( universal_telegram_check_doc_links( $universal_telegram_doc_link_paths ) );
