<?php
/**
 * `wp universal-telegram legacy-chat purge` — the supported destructive
 * removal path for obsolete legacy-chat data (ADR-0044 §5).
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Administration\Cli;

use UniversalTelegram\Persistence\LegacyChatPurge;

/**
 * Registers and dispatches `wp universal-telegram legacy-chat purge
 * --assume-legacy-chat-removal-authority [--dry-run]`.
 *
 * Drops only the tables/options in {@see \UniversalTelegram\Persistence\Migrator}'s
 * legacy manifest, preserves every transport/adapter table (bot credentials
 * included), and — before dropping the obsolete `operator_identities` table
 * — re-verifies the exact operator-identity-map bijection, aborting without
 * removing anything on any mismatch.
 */
final class LegacyChatPurgeCommand {

	/**
	 * Constructor.
	 *
	 * @param LegacyChatPurge $purge The purge service.
	 */
	public function __construct( private readonly LegacyChatPurge $purge ) {}

	/**
	 * Registers the WP-CLI command when WP-CLI is present.
	 */
	public function register(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( '\WP_CLI' ) ) {
			return;
		}

		\WP_CLI::add_command( 'universal-telegram legacy-chat', array( $this, 'dispatch' ) );
	}

	/**
	 * WP-CLI dispatcher.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : purge
	 *
	 * [--assume-legacy-chat-removal-authority]
	 * : Required to actually remove data. Without it the run is a dry run.
	 *
	 * [--dry-run]
	 * : Force a dry run (list what would be removed; touch nothing).
	 *
	 * @param array<int, string>    $args       Positional args.
	 * @param array<string, string> $assoc_args Flags.
	 */
	public function dispatch( array $args, array $assoc_args ): void {
		$action = $args[0] ?? '';

		if ( 'purge' !== $action ) {
			\WP_CLI::error( 'Usage: wp universal-telegram legacy-chat purge --assume-legacy-chat-removal-authority [--dry-run]' ); // @phpstan-ignore class.notFound
			return;
		}

		$authorised = array_key_exists( 'assume-legacy-chat-removal-authority', $assoc_args )
			&& ! array_key_exists( 'dry-run', $assoc_args );

		$report = $authorised ? $this->purge->run() : $this->purge->dry_run();

		foreach ( $report->lines() as $line ) {
			\WP_CLI::log( $line ); // @phpstan-ignore class.notFound
		}

		if ( ! $report->ok() ) {
			\WP_CLI::error( $report->summary() ); // @phpstan-ignore class.notFound
			return;
		}

		\WP_CLI::success( $report->summary() ); // @phpstan-ignore class.notFound
	}
}
