<?php
/**
 * WP-CLI import of bindings from legacy UT conversation topics (SC-M03 readiness).
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\SupportChatAdapter\Cli;

use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\SupportChatAdapter\ChannelBindingRepository;

/**
 * Reads legacy UT conversation topic mappings read-only and writes only the
 * UT binding table. Invoked as: wp universal-telegram support-chat-bindings import
 */
final class BindingImportCommand {

	/**
	 * Constructor.
	 *
	 * @param ChannelBindingRepository $bindings Binding repository.
	 */
	public function __construct( private readonly ChannelBindingRepository $bindings ) {}

	/**
	 * Registers the WP-CLI command when WP_CLI is present.
	 */
	public function register(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( '\WP_CLI' ) ) {
			return;
		}

		\WP_CLI::add_command(
			'universal-telegram support-chat-bindings',
			array( $this, 'dispatch' )
		);
	}

	/**
	 * WP-CLI dispatcher.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : import
	 *
	 * [--dry-run]
	 * : Report only; do not write bindings.
	 *
	 * [--apply]
	 * : Write bindings (required to persist; default is dry-run).
	 *
	 * @param array<int, string>   $args       Positional args.
	 * @param array<string, mixed> $assoc_args Flags.
	 */
	public function dispatch( array $args, array $assoc_args ): void {
		$action = $args[0] ?? '';
		if ( 'import' !== $action ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error -- WP-CLI path only.
			\WP_CLI::error( 'Usage: wp universal-telegram support-chat-bindings import [--dry-run|--apply]' ); // @phpstan-ignore class.notFound
		}

		$apply = isset( $assoc_args['apply'] );
		$this->import( $apply );
	}

	/**
	 * Imports bindings from legacy conversations with created topics.
	 *
	 * @param bool $apply When false, dry-run only.
	 */
	public function import( bool $apply ): void {
		global $wpdb;

		$table = $wpdb->prefix . Migrator::CONVERSATIONS_TABLE;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name from Migrator constant.
		$rows = $wpdb->get_results(
			"SELECT conversation_uuid, bot_id, destination_id, telegram_topic_id
			 FROM {$table}
			 WHERE telegram_topic_id IS NOT NULL
			   AND destination_id IS NOT NULL
			   AND topic_creation_state = 'created'
			 ORDER BY id ASC",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! is_array( $rows ) ) {
			$rows = array();
		}

		$created = 0;
		$skipped = 0;

		foreach ( $rows as $row ) {
			$conversation_uuid = (string) ( $row['conversation_uuid'] ?? '' );
			$bot_id            = (int) ( $row['bot_id'] ?? 0 );
			$destination_id    = (int) ( $row['destination_id'] ?? 0 );
			$topic_id          = (int) ( $row['telegram_topic_id'] ?? 0 );

			if ( '' === $conversation_uuid || $bot_id <= 0 || $destination_id <= 0 || $topic_id <= 0 ) {
				++$skipped;
				continue;
			}

			if ( null !== $this->bindings->find_by_conversation_uuid( $conversation_uuid )
				|| null !== $this->bindings->find_by_bot_topic( $bot_id, $topic_id ) ) {
				++$skipped;
				continue;
			}

			if ( ! $apply ) {
				++$created;
				continue;
			}

			$binding = $this->bindings->create(
				wp_generate_uuid4(),
				$conversation_uuid,
				'legacy-import:' . $conversation_uuid,
				$bot_id,
				$destination_id,
				$topic_id
			);

			if ( null === $binding ) {
				++$skipped;
				continue;
			}

			++$created;
		}

		$mode = $apply ? 'apply' : 'dry-run';
		\WP_CLI::success( sprintf( 'support-chat-bindings import (%s): would_create_or_created=%d skipped=%d total_rows=%d', $mode, $created, $skipped, count( $rows ) ) ); // @phpstan-ignore class.notFound
	}
}
